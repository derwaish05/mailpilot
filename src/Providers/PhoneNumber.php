<?php
/**
 * Phone number normalisation.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts phone numbers to E.164 (`+<country><national>`) — the format
 * strict providers such as Brevo require. Numbers already in international
 * form (leading `+` or `00`) are normalised directly; local numbers are
 * internationalised using the contact's ISO-3166 alpha-2 country (with a
 * filterable site default for forms that don't capture one).
 */
final class PhoneNumber {

	/**
	 * ISO-3166 alpha-2 → E.164 country calling code.
	 *
	 * @var array<string, string>
	 */
	private const CALLING_CODES = [
		'AF' => '93', 'AL' => '355', 'DZ' => '213', 'AS' => '1', 'AD' => '376', 'AO' => '244',
		'AI' => '1', 'AG' => '1', 'AR' => '54', 'AM' => '374', 'AW' => '297', 'AU' => '61',
		'AT' => '43', 'AZ' => '994', 'BS' => '1', 'BH' => '973', 'BD' => '880', 'BB' => '1',
		'BY' => '375', 'BE' => '32', 'BZ' => '501', 'BJ' => '229', 'BM' => '1', 'BT' => '975',
		'BO' => '591', 'BA' => '387', 'BW' => '267', 'BR' => '55', 'IO' => '246', 'BN' => '673',
		'BG' => '359', 'BF' => '226', 'BI' => '257', 'KH' => '855', 'CM' => '237', 'CA' => '1',
		'CV' => '238', 'KY' => '1', 'CF' => '236', 'TD' => '235', 'CL' => '56', 'CN' => '86',
		'CO' => '57', 'KM' => '269', 'CG' => '242', 'CD' => '243', 'CK' => '682', 'CR' => '506',
		'CI' => '225', 'HR' => '385', 'CU' => '53', 'CW' => '599', 'CY' => '357', 'CZ' => '420',
		'DK' => '45', 'DJ' => '253', 'DM' => '1', 'DO' => '1', 'EC' => '593', 'EG' => '20',
		'SV' => '503', 'GQ' => '240', 'ER' => '291', 'EE' => '372', 'SZ' => '268', 'ET' => '251',
		'FK' => '500', 'FO' => '298', 'FJ' => '679', 'FI' => '358', 'FR' => '33', 'GF' => '594',
		'PF' => '689', 'GA' => '241', 'GM' => '220', 'GE' => '995', 'DE' => '49', 'GH' => '233',
		'GI' => '350', 'GR' => '30', 'GL' => '299', 'GD' => '1', 'GP' => '590', 'GU' => '1',
		'GT' => '502', 'GG' => '44', 'GN' => '224', 'GW' => '245', 'GY' => '592', 'HT' => '509',
		'HN' => '504', 'HK' => '852', 'HU' => '36', 'IS' => '354', 'IN' => '91', 'ID' => '62',
		'IR' => '98', 'IQ' => '964', 'IE' => '353', 'IM' => '44', 'IL' => '972', 'IT' => '39',
		'JM' => '1', 'JP' => '81', 'JE' => '44', 'JO' => '962', 'KZ' => '7', 'KE' => '254',
		'KI' => '686', 'KP' => '850', 'KR' => '82', 'KW' => '965', 'KG' => '996', 'LA' => '856',
		'LV' => '371', 'LB' => '961', 'LS' => '266', 'LR' => '231', 'LY' => '218', 'LI' => '423',
		'LT' => '370', 'LU' => '352', 'MO' => '853', 'MG' => '261', 'MW' => '265', 'MY' => '60',
		'MV' => '960', 'ML' => '223', 'MT' => '356', 'MH' => '692', 'MQ' => '596', 'MR' => '222',
		'MU' => '230', 'YT' => '262', 'MX' => '52', 'FM' => '691', 'MD' => '373', 'MC' => '377',
		'MN' => '976', 'ME' => '382', 'MS' => '1', 'MA' => '212', 'MZ' => '258', 'MM' => '95',
		'NA' => '264', 'NR' => '674', 'NP' => '977', 'NL' => '31', 'NC' => '687', 'NZ' => '64',
		'NI' => '505', 'NE' => '227', 'NG' => '234', 'NU' => '683', 'NF' => '672', 'MK' => '389',
		'MP' => '1', 'NO' => '47', 'OM' => '968', 'PK' => '92', 'PW' => '680', 'PS' => '970',
		'PA' => '507', 'PG' => '675', 'PY' => '595', 'PE' => '51', 'PH' => '63', 'PL' => '48',
		'PT' => '351', 'PR' => '1', 'QA' => '974', 'RE' => '262', 'RO' => '40', 'RU' => '7',
		'RW' => '250', 'BL' => '590', 'KN' => '1', 'LC' => '1', 'MF' => '590', 'PM' => '508',
		'VC' => '1', 'WS' => '685', 'SM' => '378', 'ST' => '239', 'SA' => '966', 'SN' => '221',
		'RS' => '381', 'SC' => '248', 'SL' => '232', 'SG' => '65', 'SX' => '1', 'SK' => '421',
		'SI' => '386', 'SB' => '677', 'SO' => '252', 'ZA' => '27', 'SS' => '211', 'ES' => '34',
		'LK' => '94', 'SD' => '249', 'SR' => '597', 'SE' => '46', 'CH' => '41', 'SY' => '963',
		'TW' => '886', 'TJ' => '992', 'TZ' => '255', 'TH' => '66', 'TL' => '670', 'TG' => '228',
		'TK' => '690', 'TO' => '676', 'TT' => '1', 'TN' => '216', 'TR' => '90', 'TM' => '993',
		'TC' => '1', 'TV' => '688', 'UG' => '256', 'UA' => '380', 'AE' => '971', 'GB' => '44',
		'US' => '1', 'UY' => '598', 'UZ' => '998', 'VU' => '678', 'VA' => '39', 'VE' => '58',
		'VN' => '84', 'VG' => '1', 'VI' => '1', 'WF' => '681', 'YE' => '967', 'ZM' => '260',
		'ZW' => '263',
	];

	/**
	 * Whether a country calling code is known for the given ISO-3166 alpha-2
	 * code (used to validate the default-country setting).
	 *
	 * @param string $iso2 Two-letter country code.
	 */
	public static function is_supported_country( string $iso2 ): bool {
		return isset( self::CALLING_CODES[ strtoupper( trim( $iso2 ) ) ] );
	}

	/**
	 * Normalise a phone number to E.164, or null when it can't be.
	 *
	 * @param string|null $phone   Raw phone value.
	 * @param string|null $country ISO-3166 alpha-2 country of the contact.
	 * @return string|null `+<digits>` E.164 number, or null.
	 */
	public static function to_e164( ?string $phone, ?string $country = null ): ?string {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return null;
		}

		$plus   = str_starts_with( $phone, '+' );
		$digits = (string) preg_replace( '/\D+/', '', $phone );

		// Already international: leading "+" or the "00" call prefix.
		if ( ! $plus && str_starts_with( $digits, '00' ) ) {
			$plus   = true;
			$digits = substr( $digits, 2 );
		}

		if ( $plus ) {
			return self::finalize( $digits );
		}

		// Local number → internationalise using the contact's (or default) country.
		$code = self::calling_code( $country );
		if ( null === $code ) {
			return null;
		}

		// Drop the national trunk prefix (leading zeros) before the country code.
		$national = ltrim( $digits, '0' );
		if ( '' === $national ) {
			return null;
		}

		// Avoid double-prefixing when the number already carries the country code.
		if ( ! str_starts_with( $national, $code ) ) {
			$national = $code . $national;
		}

		return self::finalize( $national );
	}

	/**
	 * Resolve the calling code for a country, falling back to the filterable
	 * site default (`mailpilot_phone_default_country`, an ISO-3166 alpha-2
	 * code) when the contact has none.
	 *
	 * @param string|null $country ISO-3166 alpha-2 country code.
	 */
	private static function calling_code( ?string $country ): ?string {
		$country = strtoupper( trim( (string) $country ) );

		if ( '' === $country ) {
			/**
			 * Default ISO-3166 alpha-2 country for local phone numbers that
			 * arrive without a country (e.g. a form with no country field).
			 *
			 * @param string $country Default country code (empty = none).
			 */
			$country = strtoupper( (string) apply_filters( 'mailpilot_phone_default_country', '' ) );
		}

		return self::CALLING_CODES[ $country ] ?? null;
	}

	/**
	 * Validate the E.164 digit count and return the `+`-prefixed number.
	 *
	 * @param string $digits Digits only (no `+`).
	 */
	private static function finalize( string $digits ): ?string {
		$len = strlen( $digits );
		if ( $len < 8 || $len > 15 ) {
			return null;
		}

		return '+' . $digits;
	}
}
