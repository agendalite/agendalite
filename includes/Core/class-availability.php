<?php
/**
 * Availability calculations and slot resolution.
 *
 * @package LiteCal
 */

namespace LiteCal\Core;

// phpcs:disable Squiz.Commenting,WordPress.NamingConventions.ValidVariableName -- Project style preference: keep concise docs and legacy variable names without functional impact.

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Universal.Operators.DisallowShortTernary.Found -- Project style preference; no functional impact.

use LiteCal\Modules\Calendar\GoogleCalendar\GoogleCalendarModule;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uses trusted $wpdb->prefix table names and prepares dynamic values where needed.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Availability lookups query internal plugin tables.

class Availability {

	public static function get_slots( $event, $employee_id, $date, $exclude_booking_id = 0 ) {
		$context = self::resolve_schedule_context( $event );
		$ranges  = self::get_ranges( $event, $employee_id, $date, $context );
		if ( ! $ranges ) {
			return array();
		}

		$options = json_decode( $event->options ?: '[]', true );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$notice_hours         = max( 0, (int) ( $options['notice_hours'] ?? 0 ) );
		$limit_per_day        = max( 0, (int) ( $options['limit_per_day'] ?? 0 ) );
		$future_days          = max( 0, (int) ( $options['future_days'] ?? 0 ) );
		$gap_between_bookings = self::resolve_gap_minutes( $event, $options );

		$tz = $context['timezone'];
		if ( $future_days > 0 ) {
			$max_date = ( new \DateTimeImmutable( 'now', $tz ) )->modify( '+' . $future_days . ' days' )->format( 'Y-m-d' );
			if ( $date > $max_date ) {
				return array();
			}
		}

		$duration           = max( 1, (int) $event->duration );
		$step               = $duration + $gap_between_bookings;
		$bookings           = Bookings::by_date( $event->id, $employee_id, $date, $exclude_booking_id );
		$event_employees    = Events::employees( (int) $event->id );
		$apply_google_busy  = self::should_apply_google_busy( $employee_id, $event_employees );
		$google_busy_ranges = $apply_google_busy ? GoogleCalendarModule::get_busy_ranges_for_date( $date, (int) $employee_id ) : array();
		$now_ts             = ( new \DateTimeImmutable( 'now', $tz ) )->getTimestamp();
		$min_notice_ts      = $notice_hours > 0 ? ( $now_ts + ( $notice_hours * 3600 ) ) : $now_ts;

		$limit_reached = false;
		if ( $limit_per_day > 0 ) {
			$count = 0;
			foreach ( $bookings as $booking ) {
				if ( ! in_array( $booking->status, array( 'cancelled', 'deleted', 'expired' ), true ) ) {
					++$count;
				}
			}
			if ( $count >= $limit_per_day ) {
				$limit_reached = true;
			}
		}

		$slots = array();
		foreach ( $ranges as $range ) {
			$start_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $range['start'], $tz );
			$end_dt   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $range['end'], $tz );
			if ( ! $start_dt || ! $end_dt ) {
				continue;
			}
			$start  = $start_dt->getTimestamp();
			$end    = $end_dt->getTimestamp();
			$cursor = $start;
			while ( true ) {
				$slot_start          = $cursor;
				$slot_end            = $slot_start + ( $duration * 60 );
				$slot_window_start   = $slot_start;
				$slot_window_end     = $slot_end;
				$slot_gap_window_end = $slot_end + ( $gap_between_bookings * 60 );
				if ( $slot_window_end > $end ) {
					break;
				}

				$status = 'available';
				if ( $limit_reached ) {
					$status = 'unavailable';
				} elseif ( $slot_start < $min_notice_ts ) {
					$status = 'unavailable';
				} elseif ( self::is_time_off( $employee_id, $date ) ) {
					$status = 'unavailable';
				}
				foreach ( $bookings as $booking ) {
					$b_start_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $booking->start_datetime, $tz );
					$b_end_dt   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $booking->end_datetime, $tz );
					if ( ! $b_start_dt || ! $b_end_dt ) {
						continue;
					}
					$b_start             = $b_start_dt->getTimestamp();
					$b_end               = $b_end_dt->getTimestamp();
					$booking_gap_between = $gap_between_bookings;
					if ( ! empty( $booking->snapshot ) ) {
						$snapshot = json_decode( (string) $booking->snapshot, true );
						if ( is_array( $snapshot ) ) {
							if ( array_key_exists( 'gap_between_bookings', (array) ( $snapshot['event'] ?? array() ) ) ) {
								$booking_gap_between = max( 0, (int) ( $snapshot['event']['gap_between_bookings'] ?? 0 ) );
							} else {
								$booking_gap_between = max(
									0,
									(int) ( $snapshot['event']['buffer_before'] ?? 0 ),
									(int) ( $snapshot['event']['buffer_after'] ?? 0 )
								);
							}
						}
					}
					$b_window_start = $b_start;
					$b_window_end   = $b_end + ( $booking_gap_between * 60 );
					$overlap        = self::overlaps( $slot_window_start, $slot_gap_window_end, $b_window_start, $b_window_end );
					if ( $overlap ) {
						if ( in_array( $booking->status, array( 'cancelled', 'deleted', 'expired' ), true ) ) {
							continue;
						}
						$status = 'unavailable';
						break;
					}
				}
				if ( $status === 'available' && ! empty( $google_busy_ranges ) ) {
					foreach ( $google_busy_ranges as $busy_range ) {
						$busy_start = (int) ( $busy_range['start'] ?? 0 );
						$busy_end   = (int) ( $busy_range['end'] ?? 0 );
						if ( $busy_end <= $busy_start ) {
							continue;
						}
						if ( self::overlaps( $slot_window_start, $slot_window_end, $busy_start, $busy_end ) ) {
							$status = 'unavailable';
							break;
						}
					}
				}
				$slots[] = array(
					'start'  => wp_date( 'H:i', $slot_start, $tz ),
					'end'    => wp_date( 'H:i', $slot_end, $tz ),
					'status' => $status,
				);

				$cursor += ( $step * 60 );
			}
		}

		return $slots;
	}

	public static function resolve_gap_minutes( $event, $options = null ) {
		if ( ! is_array( $options ) ) {
			$options = json_decode( $event->options ?: '[]', true );
			if ( ! is_array( $options ) ) {
				$options = array();
			}
		}
		if ( array_key_exists( 'gap_between_bookings', $options ) ) {
			return max( 0, (int) $options['gap_between_bookings'] );
		}
		return max( 0, (int) ( $event->buffer_before ?? 0 ), (int) ( $event->buffer_after ?? 0 ) );
	}

	public static function resolve_timezone( $event ) {
		$context = self::resolve_schedule_context( $event );
		return $context['timezone'];
	}

	private static function get_ranges( $event, $employee_id, $date, $context = null ) {
		if ( ! is_array( $context ) ) {
			$context = self::resolve_schedule_context( $event );
		}
		$tz          = $context['timezone'];
		$date_obj    = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $tz );
		$fallback_ts = strtotime( (string) $date );
		$day         = $date_obj ? (int) $date_obj->format( 'N' ) : (int) wp_date( 'N', $fallback_ts, $tz );
		$schedules   = $context['schedules'];
		$schedule_id = $context['schedule_id'];
		$schedule    = is_array( $schedules[ $schedule_id ] ?? null ) ? $schedules[ $schedule_id ] : array();
		$ranges      = array();

		if ( ! empty( $schedule['days'][ $day ] ) && is_array( $schedule['days'][ $day ] ) ) {
			foreach ( $schedule['days'][ $day ] as $range ) {
				$start = self::normalize_time_hhmm( $range['start'] ?? '' );
				$end   = self::normalize_time_hhmm( $range['end'] ?? '' );
				if ( $start === '' || $end === '' ) {
					continue;
				}
				if ( self::time_hhmm_to_minutes( $start ) >= self::time_hhmm_to_minutes( $end ) ) {
					continue;
				}
				$ranges[] = array(
					'start' => $start,
					'end'   => $end,
				);
			}
		}

		// Backward compatibility: when legacy schedules saved only bounds + breaks.
		if ( empty( $ranges ) && ! empty( $schedule['bounds'][ $day ] ) && is_array( $schedule['bounds'][ $day ] ) ) {
			$bound_start = self::normalize_time_hhmm( $schedule['bounds'][ $day ]['start'] ?? '' );
			$bound_end   = self::normalize_time_hhmm( $schedule['bounds'][ $day ]['end'] ?? '' );
			if ( $bound_start !== '' && $bound_end !== '' && self::time_hhmm_to_minutes( $bound_start ) < self::time_hhmm_to_minutes( $bound_end ) ) {
				$ranges[] = array(
					'start' => $bound_start,
					'end'   => $bound_end,
				);
			}
		}

		$break_start = self::normalize_time_hhmm( $schedule['breaks'][ $day ]['start'] ?? '' );
		$break_end   = self::normalize_time_hhmm( $schedule['breaks'][ $day ]['end'] ?? '' );
		if ( $break_start !== '' && $break_end !== '' && self::time_hhmm_to_minutes( $break_start ) < self::time_hhmm_to_minutes( $break_end ) ) {
			$ranges = self::apply_break_to_ranges( $ranges, $break_start, $break_end );
		}

		if ( empty( $ranges ) ) {
			return array();
		}

		return array_map(
			function ( $range ) {
				return array(
					'start' => $range['start'] . ':00',
					'end'   => $range['end'] . ':00',
				);
			},
			$ranges
		);
	}

	private static function normalize_time_hhmm( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^\d{2}:\d{2}:\d{2}$/', $value ) ) {
			return substr( $value, 0, 5 );
		}
		return '';
	}

	private static function time_hhmm_to_minutes( $value ) {
		$parts = explode( ':', (string) $value );
		if ( count( $parts ) < 2 ) {
			return -1;
		}
		$hours   = (int) $parts[0];
		$minutes = (int) $parts[1];
		if ( $hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59 ) {
			return -1;
		}
		return ( $hours * 60 ) + $minutes;
	}

	private static function apply_break_to_ranges( $ranges, $break_start, $break_end ) {
		$break_start_minutes = self::time_hhmm_to_minutes( $break_start );
		$break_end_minutes   = self::time_hhmm_to_minutes( $break_end );
		if ( $break_start_minutes < 0 || $break_end_minutes < 0 || $break_start_minutes >= $break_end_minutes ) {
			return (array) $ranges;
		}
		$result = array();
		foreach ( (array) $ranges as $range ) {
			$range_start = self::normalize_time_hhmm( $range['start'] ?? '' );
			$range_end   = self::normalize_time_hhmm( $range['end'] ?? '' );
			if ( $range_start === '' || $range_end === '' ) {
				continue;
			}
			$range_start_minutes = self::time_hhmm_to_minutes( $range_start );
			$range_end_minutes   = self::time_hhmm_to_minutes( $range_end );
			if ( $range_start_minutes < 0 || $range_end_minutes < 0 || $range_start_minutes >= $range_end_minutes ) {
				continue;
			}

			// No overlap with break: keep original range.
			if ( $break_end_minutes <= $range_start_minutes || $break_start_minutes >= $range_end_minutes ) {
				$result[] = array(
					'start' => $range_start,
					'end'   => $range_end,
				);
				continue;
			}

			if ( $range_start_minutes < $break_start_minutes ) {
				$result[] = array(
					'start' => $range_start,
					'end'   => $break_start,
				);
			}
			if ( $break_end_minutes < $range_end_minutes ) {
				$result[] = array(
					'start' => $break_end,
					'end'   => $range_end,
				);
			}
		}
		return $result;
	}

	private static function resolve_schedule_context( $event ) {
		$schedules   = get_option( 'litecal_schedules', array() );
		$default_id  = (string) get_option( 'litecal_default_schedule', 'default' );
		$schedule_id = $default_id;
		$options     = json_decode( $event->options ?: '[]', true );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		if ( ! empty( $event->availability_override ) ) {
			$candidate_id = sanitize_text_field( (string) ( $options['schedule_id'] ?? $default_id ) );
			if ( $candidate_id !== '' ) {
				$schedule_id = $candidate_id;
			}
		}
		$tz      = wp_timezone();
		$tz_name = Helpers::resolve_schedule_timezone_name( $schedules[ $schedule_id ]['timezone'] ?? '' );
		if ( $tz_name !== '' ) {
			try {
				$tz = new \DateTimeZone( $tz_name );
			} catch ( \Throwable $e ) {
				$tz = wp_timezone();
			}
		}

		return array(
			'schedule_id' => $schedule_id,
			'schedules'   => is_array( $schedules ) ? $schedules : array(),
			'timezone'    => $tz,
		);
	}

	private static function is_time_off( $employee_id, $date ) {
		global $wpdb;
		$table = $wpdb->prefix . 'litecal_time_off';
		$rows  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE (scope = 'employee' AND scope_id = %d OR scope = 'global') AND %s BETWEEN start_date AND end_date",
				$employee_id,
				$date
			)
		);
		return (int) $rows > 0;
	}

	private static function is_double_booked( $employee_id, $start_ts, $end_ts ) {
		if ( empty( $employee_id ) ) {
			return false;
		}
		$start = wp_date( 'Y-m-d H:i:s', $start_ts, wp_timezone() );
		$end   = wp_date( 'Y-m-d H:i:s', $end_ts, wp_timezone() );
		return Bookings::exists_overlap( $employee_id, $start, $end );
	}

	private static function overlaps( $a_start, $a_end, $b_start, $b_end ) {
		return $a_start < $b_end && $a_end > $b_start;
	}

	private static function should_apply_google_busy( $employee_id, $event_employees ) {
		// Cuando hay selección explícita de profesional y el sitio tiene más de un integrante,
		// no usamos "busy" global de Google Calendar para no mezclar bloqueos entre profesionals.
		if ( (int) $employee_id > 0 ) {
			$all_employees = Employees::all_booking_managers( true );
			$active_count  = 0;
			foreach ( (array) $all_employees as $employee ) {
				$status = sanitize_key( (string) ( $employee->status ?? 'active' ) );
				if ( $status !== 'inactive' ) {
					++$active_count;
				}
			}
			if ( $active_count > 1 ) {
				return false;
			}
		}
		return count( (array) $event_employees ) <= 1;
	}
}
