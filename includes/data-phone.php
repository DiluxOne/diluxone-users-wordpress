<?php
/**
 * How long a phone number is in each country, how it is written, and the
 * prefix people dial before it at home.
 *
 * Data beside data: the countries and their dialling codes are in
 * data-countries.php, and what is needed to tell a number of the right length
 * from a wrong one is here, one row per country of that list. Nothing in the
 * code names a country; whatever is particular to one is in its row.
 *
 * A row is `ISO => [min, max, grouping, trunk]`:
 *
 * - min, max: how many digits the national number has, the part after the
 *   dialling code, as the ITU-T E.164 national numbering plans give them (in
 *   the compilation libphonenumber keeps of them), for fixed lines and mobiles.
 *   Where the dialling code of the list already carries an area code (+1 268,
 *   Antigua), the range is what comes after it.
 * - grouping: how the digits are written, as sizes of groups — `2 4 4` is
 *   "11 2345 6789" — one pattern per length, separated by `|`. A country with
 *   none, or a length with none, is written in groups of three ending in four.
 * - trunk: the prefix dialled before a national number inside the country
 *   (0 in most, 1 across the North American plan, 8 in some, 06 in Hungary,
 *   none in many). It is never part of the number: typed out of habit, it is
 *   dropped before the length is checked and before the number is stored.
 *
 * Plain functions over a static table, without WordPress, so it is tested
 * as it is.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every country's rule.
 *
 * @return array<string, array{0: int, 1: int, 2: string, 3: string}> ISO => [min, max, grouping, trunk].
 */
function diluxone_users_phone_rules(): array {
	static $rules = null;

	if ( null === $rules ) {
		$rules = array(
			'AF' => array( 9, 9, '2 3 4', '0' ),
			'AL' => array( 8, 9, '2 3 3|2 3 4', '0' ),
			'DZ' => array( 8, 9, '2 2 2 2|3 2 2 2', '0' ),
			'AD' => array( 6, 9, '', '' ),
			'AO' => array( 9, 9, '', '' ),
			'AG' => array( 7, 7, '', '1' ),
			'AR' => array( 10, 11, '2 4 4|1 2 4 4', '0' ),
			'AM' => array( 8, 8, '2 6', '0' ),
			'AW' => array( 7, 7, '', '' ),
			'AU' => array( 9, 9, '1 4 4', '0' ),
			'AT' => array( 4, 13, '3 6|1 9', '0' ),
			'AZ' => array( 9, 9, '2 3 2 2', '0' ),
			'BS' => array( 7, 7, '', '1' ),
			'BH' => array( 8, 8, '', '' ),
			'BD' => array( 6, 10, '1 7|4 6', '0' ),
			'BB' => array( 7, 7, '', '1' ),
			'BY' => array( 9, 9, '3 2 2 2', '8' ),
			'BE' => array( 8, 9, '2 2 2 2|3 2 2 2', '0' ),
			'BZ' => array( 7, 7, '', '' ),
			'BJ' => array( 10, 10, '2 2 2 2 2', '' ),
			'BM' => array( 7, 7, '', '1' ),
			'BT' => array( 7, 8, '1 3 3|2 2 2 2', '' ),
			'BO' => array( 8, 8, '1 7', '0' ),
			'BA' => array( 8, 9, '2 3 3', '0' ),
			'BW' => array( 7, 8, '3 4|2 3 3', '' ),
			'BR' => array( 10, 11, '2 4 4|2 5 4', '0' ),
			'BN' => array( 7, 7, '', '' ),
			'BG' => array( 6, 9, '1 3 3|2 3 3', '0' ),
			'BF' => array( 8, 8, '2 2 2 2', '' ),
			'BI' => array( 8, 8, '2 2 2 2', '' ),
			'KH' => array( 8, 9, '2 3 3', '0' ),
			'CM' => array( 9, 9, '1 2 2 2 2', '' ),
			'CA' => array( 10, 10, '', '1' ),
			'CV' => array( 7, 7, '3 2 2', '' ),
			'KY' => array( 7, 7, '', '1' ),
			'CF' => array( 8, 8, '2 2 2 2', '' ),
			'TD' => array( 8, 8, '2 2 2 2', '' ),
			'CL' => array( 9, 9, '', '' ),
			'CN' => array( 7, 11, '2 4 4|3 4 4', '0' ),
			'CO' => array( 8, 10, '3 7', '0' ),
			'KM' => array( 7, 7, '3 2 2', '' ),
			'CG' => array( 9, 9, '2 3 4', '' ),
			'CD' => array( 7, 10, '2 5|3 3 3', '0' ),
			'CR' => array( 8, 8, '', '' ),
			'CI' => array( 10, 10, '2 2 1 5', '' ),
			'HR' => array( 8, 9, '1 4 3|2 3 4', '0' ),
			'CU' => array( 6, 10, '1 7', '0' ),
			'CW' => array( 7, 8, '1 3 4', '' ),
			'CY' => array( 8, 8, '2 6', '' ),
			'CZ' => array( 9, 9, '', '' ),
			'DK' => array( 8, 8, '2 2 2 2', '' ),
			'DJ' => array( 8, 8, '2 2 2 2', '' ),
			'DM' => array( 7, 7, '', '1' ),
			'DO' => array( 7, 7, '', '1' ),
			'EC' => array( 8, 9, '1 3 4|2 3 4', '0' ),
			'EG' => array( 8, 10, '1 8|2 8', '0' ),
			'SV' => array( 8, 8, '', '' ),
			'GQ' => array( 9, 9, '', '' ),
			'ER' => array( 7, 7, '1 3 3', '0' ),
			'EE' => array( 7, 8, '', '' ),
			'SZ' => array( 8, 8, '', '' ),
			'ET' => array( 9, 9, '2 3 4', '0' ),
			'FJ' => array( 7, 7, '', '' ),
			'FI' => array( 5, 10, '2 7', '0' ),
			'FR' => array( 9, 9, '1 2 2 2 2', '0' ),
			'GA' => array( 7, 8, '2 2 2 2', '' ),
			'GM' => array( 7, 9, '', '' ),
			'GE' => array( 9, 9, '2 3 2 2', '0' ),
			'DE' => array( 5, 13, '2 6|4 7', '0' ),
			'GH' => array( 9, 9, '2 3 4', '0' ),
			'GI' => array( 8, 8, '3 5', '' ),
			'GR' => array( 10, 10, '2 4 4', '' ),
			'GL' => array( 6, 6, '2 2 2', '' ),
			'GD' => array( 7, 7, '', '1' ),
			'GT' => array( 8, 8, '', '' ),
			'GN' => array( 8, 9, '2 2 2 2|3 2 2 2', '' ),
			'GW' => array( 9, 9, '', '' ),
			'GY' => array( 7, 7, '', '' ),
			'HT' => array( 8, 8, '2 2 4', '' ),
			'HN' => array( 8, 8, '', '' ),
			'HK' => array( 8, 8, '', '' ),
			'HU' => array( 8, 9, '1 3 4|2 3 4', '06' ),
			'IS' => array( 7, 9, '', '' ),
			'IN' => array( 10, 10, '5 5', '0' ),
			'ID' => array( 7, 12, '2 7', '0' ),
			'IR' => array( 6, 10, '2 4 4', '0' ),
			'IQ' => array( 8, 10, '1 3 4|3 3 4', '0' ),
			'IE' => array( 7, 10, '2 5|2 3 4', '0' ),
			'IL' => array( 8, 12, '1 3 4|2 3 4', '0' ),
			'IT' => array( 6, 12, '2 4 4', '' ),
			'JM' => array( 7, 7, '', '1' ),
			'JP' => array( 9, 10, '1 4 4|2 4 4', '0' ),
			'JO' => array( 8, 9, '1 3 4|1 4 4', '0' ),
			'KZ' => array( 10, 10, '5 1 2 2', '8' ),
			'KE' => array( 7, 9, '2 7', '0' ),
			'KW' => array( 8, 8, '', '' ),
			'KG' => array( 9, 9, '', '0' ),
			'LA' => array( 8, 10, '2 3 3|2 2 3 3', '0' ),
			'LV' => array( 8, 8, '2 3 3', '' ),
			'LB' => array( 7, 8, '1 3 3|2 3 3', '0' ),
			'LS' => array( 8, 8, '', '' ),
			'LR' => array( 7, 9, '2 3 3|2 3 4', '0' ),
			'LY' => array( 9, 9, '2 7', '0' ),
			'LI' => array( 7, 9, '3 2 2|3 3 3', '0' ),
			'LT' => array( 8, 8, '3 5', '0' ),
			'LU' => array( 4, 11, '2 2 2 2|3 3 3', '' ),
			'MO' => array( 8, 8, '', '' ),
			'MG' => array( 9, 9, '2 2 3 2', '0' ),
			'MW' => array( 7, 9, '1 3 3|3 2 2 2', '0' ),
			'MY' => array( 8, 10, '1 4 4', '0' ),
			'MV' => array( 7, 7, '', '' ),
			'ML' => array( 8, 8, '2 2 2 2', '' ),
			'MT' => array( 8, 8, '', '' ),
			'MR' => array( 8, 8, '2 2 2 2', '' ),
			'MU' => array( 7, 8, '', '' ),
			'MX' => array( 10, 10, '', '' ),
			'MD' => array( 8, 8, '2 3 3', '0' ),
			'MC' => array( 8, 9, '2 2 2 2|1 2 2 2 2', '0' ),
			'MN' => array( 8, 10, '', '0' ),
			'ME' => array( 8, 8, '2 3 3', '0' ),
			'MA' => array( 9, 9, '1 2 2 2 2', '0' ),
			'MZ' => array( 8, 9, '2 3 3|2 3 4', '' ),
			'MM' => array( 6, 10, '1 3 3|1 3 4', '0' ),
			'NA' => array( 8, 9, '2 3 3|2 3 4', '0' ),
			'NP' => array( 8, 10, '1 7|3 7', '0' ),
			'NL' => array( 9, 11, '2 3 4', '0' ),
			'NZ' => array( 8, 10, '1 3 4|2 3 4', '0' ),
			'NI' => array( 8, 8, '', '' ),
			'NE' => array( 8, 8, '2 2 2 2', '' ),
			'NG' => array( 10, 10, '4 2 4', '0' ),
			'MK' => array( 8, 8, '1 3 4', '0' ),
			'NO' => array( 8, 8, '2 2 2 2', '' ),
			'OM' => array( 8, 8, '2 6', '' ),
			'PK' => array( 9, 10, '2 8', '0' ),
			'PS' => array( 8, 9, '1 3 4|3 3 3', '0' ),
			'PA' => array( 7, 8, '', '' ),
			'PG' => array( 7, 8, '', '' ),
			'PY' => array( 7, 9, '2 3 4', '0' ),
			'PE' => array( 8, 9, '1 7|3 3 3', '0' ),
			'PH' => array( 6, 10, '1 4 4|3 3 4', '0' ),
			'PL' => array( 7, 9, '2 3 2 2', '' ),
			'PT' => array( 9, 9, '2 3 4', '' ),
			'PR' => array( 7, 7, '', '1' ),
			'QA' => array( 8, 8, '', '' ),
			'RO' => array( 6, 9, '2 3 4', '0' ),
			'RU' => array( 10, 10, '3 3 2 2', '8' ),
			'RW' => array( 8, 9, '', '0' ),
			'SM' => array( 8, 10, '2 2 2 2|4 6', '' ),
			'SA' => array( 9, 9, '2 3 4', '0' ),
			'SN' => array( 9, 9, '2 3 2 2', '' ),
			'RS' => array( 7, 12, '2 6|2 7', '0' ),
			'SC' => array( 7, 7, '1 3 3', '' ),
			'SL' => array( 8, 8, '2 6', '0' ),
			'SG' => array( 8, 8, '', '' ),
			'SK' => array( 6, 9, '1 3 3 2', '0' ),
			'SI' => array( 8, 8, '1 3 2 2', '0' ),
			'SO' => array( 6, 9, '1 6|1 7', '0' ),
			'ZA' => array( 5, 9, '2 3 4', '0' ),
			'KR' => array( 5, 10, '1 3 4|2 4 4', '0' ),
			'SS' => array( 9, 9, '', '0' ),
			'ES' => array( 9, 9, '3 2 2 2', '' ),
			'LK' => array( 9, 9, '', '0' ),
			'SD' => array( 9, 9, '2 3 4', '0' ),
			'SR' => array( 6, 7, '', '' ),
			'SE' => array( 7, 9, '1 2 2 2|2 3 2 2', '0' ),
			'CH' => array( 9, 9, '2 3 2 2', '0' ),
			'SY' => array( 8, 9, '2 3 4', '0' ),
			'TW' => array( 8, 9, '1 4 4', '0' ),
			'TJ' => array( 9, 9, '3 2 4', '' ),
			'TZ' => array( 9, 9, '2 3 4', '0' ),
			'TH' => array( 8, 9, '1 3 4|2 3 4', '0' ),
			'TL' => array( 7, 8, '', '' ),
			'TG' => array( 8, 8, '2 2 2 2', '' ),
			'TT' => array( 7, 7, '', '1' ),
			'TN' => array( 8, 8, '2 3 3', '' ),
			'TR' => array( 10, 10, '3 3 2 2', '0' ),
			'TM' => array( 8, 8, '2 2 2 2', '8' ),
			'UG' => array( 9, 9, '2 7', '0' ),
			'UA' => array( 9, 9, '4 5', '0' ),
			'AE' => array( 8, 9, '1 3 4|2 3 4', '0' ),
			'GB' => array( 9, 10, '', '0' ),
			'US' => array( 10, 10, '', '1' ),
			'UY' => array( 8, 8, '', '0' ),
			'UZ' => array( 9, 9, '2 3 2 2', '' ),
			'VE' => array( 10, 10, '3 7', '0' ),
			'VN' => array( 9, 10, '3 3 3|3 4 3', '0' ),
			'YE' => array( 7, 9, '1 3 3|3 3 3', '0' ),
			'ZM' => array( 9, 9, '', '0' ),
			'ZW' => array( 7, 9, '2 5|2 3 4', '0' ),
			'AI' => array( 7, 7, '', '1' ),
			'AQ' => array( 6, 6, '2 4', '' ),
			'AS' => array( 7, 7, '', '1' ),
			'AX' => array( 6, 10, '2 7', '0' ),
			'BL' => array( 9, 9, '3 2 2 2', '0' ),
			'BQ' => array( 7, 7, '', '' ),
			'CC' => array( 9, 9, '1 4 4', '0' ),
			'CK' => array( 5, 5, '', '' ),
			'CX' => array( 9, 9, '1 4 4', '0' ),
			'EH' => array( 9, 9, '1 2 2 2 2', '0' ),
			'FK' => array( 5, 5, '5', '' ),
			'FM' => array( 7, 7, '', '' ),
			'FO' => array( 6, 6, '2 2 2', '' ),
			'GF' => array( 9, 9, '3 2 2 2', '0' ),
			'GG' => array( 10, 10, '4 6', '0' ),
			'GP' => array( 9, 9, '3 2 2 2', '0' ),
			'GS' => array( 5, 5, '5', '' ),
			'GU' => array( 7, 7, '', '1' ),
			'IM' => array( 10, 10, '4 6', '0' ),
			'IO' => array( 7, 7, '', '' ),
			'JE' => array( 10, 10, '4 6', '0' ),
			'KI' => array( 5, 8, '5|8', '0' ),
			'KN' => array( 7, 7, '', '1' ),
			'KP' => array( 8, 10, '1 3 4|3 3 4', '0' ),
			'LC' => array( 7, 7, '', '1' ),
			'MF' => array( 9, 9, '3 2 2 2', '0' ),
			'MH' => array( 7, 7, '', '1' ),
			'MP' => array( 7, 7, '', '1' ),
			'MQ' => array( 9, 9, '3 2 2 2', '0' ),
			'MS' => array( 7, 7, '', '1' ),
			'NC' => array( 6, 6, '6', '' ),
			'NF' => array( 6, 6, '2 4', '' ),
			'NR' => array( 7, 7, '', '' ),
			'NU' => array( 4, 7, '', '' ),
			'PF' => array( 8, 8, '2 2 2 2', '' ),
			'PM' => array( 6, 9, '2 2 2', '0' ),
			'PN' => array( 8, 10, '1 3 4|2 3 4', '' ),
			'PW' => array( 7, 7, '', '' ),
			'RE' => array( 9, 9, '3 2 2 2', '0' ),
			'SB' => array( 5, 7, '5|2 5', '' ),
			'SH' => array( 4, 5, '5', '' ),
			'SJ' => array( 8, 8, '2 2 2 2', '' ),
			'ST' => array( 7, 7, '', '' ),
			'SX' => array( 7, 7, '', '1' ),
			'TC' => array( 7, 7, '', '1' ),
			'TF' => array( 9, 9, '3 2 2 2', '0' ),
			'TK' => array( 4, 7, '', '' ),
			'TO' => array( 5, 7, '', '' ),
			'TV' => array( 5, 7, '2 3|2 4', '' ),
			'UM' => array( 10, 10, '', '1' ),
			'VC' => array( 7, 7, '', '1' ),
			'VG' => array( 7, 7, '', '1' ),
			'VI' => array( 7, 7, '', '1' ),
			'VU' => array( 5, 7, '5|3 4', '' ),
			'WF' => array( 6, 6, '2 2 2', '' ),
			'WS' => array( 5, 10, '5|2 5', '' ),
			'XK' => array( 8, 12, '2 3 3', '0' ),
			'YT' => array( 9, 9, '3 2 2 2', '0' ),
		);
	}

	return $rules;
}

/**
 * One country's rule.
 *
 * A country the table does not have — one a filter added to the list — is
 * held to what E.164 says of every number: at most fifteen digits counting the
 * dialling code.
 *
 * @return array{min: int, max: int, group: string, trunk: string}
 */
function diluxone_users_phone_rule( string $iso ): array {
	$iso   = strtoupper( $iso );
	$rules = diluxone_users_phone_rules();

	if ( isset( $rules[ $iso ] ) ) {
		return array(
			'min'   => $rules[ $iso ][0],
			'max'   => $rules[ $iso ][1],
			'group' => $rules[ $iso ][2],
			'trunk' => $rules[ $iso ][3],
		);
	}

	return array(
		'min'   => 4,
		'max'   => max( 4, 15 - strlen( diluxone_users_country_dial( $iso ) ) ),
		'group' => '',
		'trunk' => '',
	);
}

/**
 * The national number, as it is checked and stored: digits only, without the
 * trunk prefix typed out of habit ("011 2345 6789" in Argentina is 11 2345
 * 6789).
 *
 * The prefix goes only when what is left is still long enough to be a number
 * of that country. A number that itself starts with the same digit — a
 * Russian 800, which begins with the 8 a Russian dials as a trunk prefix — is
 * left whole.
 */
function diluxone_users_phone_national( string $iso, string $number ): string {
	$digits = (string) preg_replace( '/\D/', '', $number );
	$rule   = diluxone_users_phone_rule( $iso );
	$trunk  = $rule['trunk'];

	if ( '' !== $trunk && 0 === strpos( $digits, $trunk ) && strlen( $digits ) - strlen( $trunk ) >= $rule['min'] ) {
		return substr( $digits, strlen( $trunk ) );
	}

	return $digits;
}

/**
 * Is the number the right length for the country?
 *
 * @param string $iso      The country chosen.
 * @param string $national The number as typed, with or without spaces and the trunk prefix.
 * @return string '' when it is (or when nothing was typed), 'short' or 'long'.
 */
function diluxone_users_phone_check( string $iso, string $national ): string {
	$digits = diluxone_users_phone_national( $iso, $national );

	if ( '' === $digits ) {
		return '';
	}

	$rule = diluxone_users_phone_rule( $iso );

	if ( strlen( $digits ) < $rule['min'] ) {
		return 'short';
	}

	return strlen( $digits ) > $rule['max'] ? 'long' : '';
}

/**
 * The sizes of the groups a number of this length is written in.
 *
 * The country's pattern for that length when it has one; a number still
 * being typed is written as the shortest number of the country would be, and
 * one past the longest as the longest, with what is left over as a last group.
 *
 * @return array<int, int>
 */
function diluxone_users_phone_groups( string $iso, int $length ): array {
	$rule     = diluxone_users_phone_rule( $iso );
	$target   = min( max( $length, $rule['min'] ), $rule['max'] );
	$patterns = array();

	foreach ( '' === $rule['group'] ? array() : explode( '|', $rule['group'] ) as $pattern ) {
		$sizes = array_map( 'intval', explode( ' ', $pattern ) );

		$patterns[ array_sum( $sizes ) ] = $sizes;
	}

	if ( isset( $patterns[ $target ] ) ) {
		return $patterns[ $target ];
	}

	// Threes, and a four at the end: 3 4, 4 4, 3 3 3, 3 3 4, 3 4 4.
	if ( $target <= 4 ) {
		return array( $target );
	}

	if ( 5 === $target ) {
		return array( 2, 3 );
	}

	$fours = array( 0, 1, 2 )[ $target % 3 ];

	return array_merge( array_fill( 0, intdiv( $target - 4 * $fours, 3 ), 3 ), array_fill( 0, $fours, 4 ) );
}

/**
 * The number written as people in that country write it: "11 2345 6789".
 *
 * @param string $iso      The country.
 * @param string $national The national number, with or without spaces and the trunk prefix.
 */
function diluxone_users_phone_format( string $iso, string $national ): string {
	$digits = diluxone_users_phone_national( $iso, $national );
	$length = strlen( $digits );
	$parts  = array();
	$at     = 0;

	foreach ( diluxone_users_phone_groups( $iso, $length ) as $size ) {
		if ( $at >= $length ) {
			break;
		}

		$parts[] = substr( $digits, $at, $size );
		$at     += $size;
	}

	if ( $at < $length ) {
		$parts[] = substr( $digits, $at );
	}

	return implode( ' ', $parts );
}
