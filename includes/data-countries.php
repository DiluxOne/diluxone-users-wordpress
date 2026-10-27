<?php
/**
 * Countries, with their ISO code and their dialling code.
 *
 * It is a data file and nothing more. It exists so that nobody has to type
 * "Argentina" by hand into a text field: the "country" field type comes from
 * here, and the phone one uses the dialling code to assemble the full number.
 *
 * The ISO code is what is stored; the name is only for display, and comes
 * translated into the site's language (the English name is the msgid). That
 * way the value does not break if the translation changes tomorrow, and
 * filtering by country needs no string comparison.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every country.
 *
 * @return array<string, array{0: string, 1: string}> ISO => [translated name, dialling code].
 */
function diluxone_users_countries(): array {
	// One list per language: a mail written in its recipient's language
	// (switch_to_locale()) asks for it in the middle of a request drawn in
	// another one, and must not get the names the first call translated.
	static $cache = array();

	$locale = get_locale();

	if ( ! isset( $cache[ $locale ] ) ) {
		$countries = array(
			'AF' => array( __( 'Afghanistan', 'diluxone-users' ), '93' ),
			'AL' => array( __( 'Albania', 'diluxone-users' ), '355' ),
			'DZ' => array( __( 'Algeria', 'diluxone-users' ), '213' ),
			'AD' => array( __( 'Andorra', 'diluxone-users' ), '376' ),
			'AO' => array( __( 'Angola', 'diluxone-users' ), '244' ),
			'AG' => array( __( 'Antigua and Barbuda', 'diluxone-users' ), '1268' ),
			'AR' => array( __( 'Argentina', 'diluxone-users' ), '54' ),
			'AM' => array( __( 'Armenia', 'diluxone-users' ), '374' ),
			'AW' => array( __( 'Aruba', 'diluxone-users' ), '297' ),
			'AU' => array( __( 'Australia', 'diluxone-users' ), '61' ),
			'AT' => array( __( 'Austria', 'diluxone-users' ), '43' ),
			'AZ' => array( __( 'Azerbaijan', 'diluxone-users' ), '994' ),
			'BS' => array( __( 'Bahamas', 'diluxone-users' ), '1242' ),
			'BH' => array( __( 'Bahrain', 'diluxone-users' ), '973' ),
			'BD' => array( __( 'Bangladesh', 'diluxone-users' ), '880' ),
			'BB' => array( __( 'Barbados', 'diluxone-users' ), '1246' ),
			'BY' => array( __( 'Belarus', 'diluxone-users' ), '375' ),
			'BE' => array( __( 'Belgium', 'diluxone-users' ), '32' ),
			'BZ' => array( __( 'Belize', 'diluxone-users' ), '501' ),
			'BJ' => array( __( 'Benin', 'diluxone-users' ), '229' ),
			'BM' => array( __( 'Bermuda', 'diluxone-users' ), '1441' ),
			'BT' => array( __( 'Bhutan', 'diluxone-users' ), '975' ),
			'BO' => array( __( 'Bolivia', 'diluxone-users' ), '591' ),
			'BA' => array( __( 'Bosnia and Herzegovina', 'diluxone-users' ), '387' ),
			'BW' => array( __( 'Botswana', 'diluxone-users' ), '267' ),
			'BR' => array( __( 'Brazil', 'diluxone-users' ), '55' ),
			'BN' => array( __( 'Brunei', 'diluxone-users' ), '673' ),
			'BG' => array( __( 'Bulgaria', 'diluxone-users' ), '359' ),
			'BF' => array( __( 'Burkina Faso', 'diluxone-users' ), '226' ),
			'BI' => array( __( 'Burundi', 'diluxone-users' ), '257' ),
			'KH' => array( __( 'Cambodia', 'diluxone-users' ), '855' ),
			'CM' => array( __( 'Cameroon', 'diluxone-users' ), '237' ),
			'CA' => array( __( 'Canada', 'diluxone-users' ), '1' ),
			'CV' => array( __( 'Cape Verde', 'diluxone-users' ), '238' ),
			'KY' => array( __( 'Cayman Islands', 'diluxone-users' ), '1345' ),
			'CF' => array( __( 'Central African Republic', 'diluxone-users' ), '236' ),
			'TD' => array( __( 'Chad', 'diluxone-users' ), '235' ),
			'CL' => array( __( 'Chile', 'diluxone-users' ), '56' ),
			'CN' => array( __( 'China', 'diluxone-users' ), '86' ),
			'CO' => array( __( 'Colombia', 'diluxone-users' ), '57' ),
			'KM' => array( __( 'Comoros', 'diluxone-users' ), '269' ),
			'CG' => array( __( 'Congo', 'diluxone-users' ), '242' ),
			'CD' => array( __( 'Congo (DRC)', 'diluxone-users' ), '243' ),
			'CR' => array( __( 'Costa Rica', 'diluxone-users' ), '506' ),
			'CI' => array( __( 'Côte d\'Ivoire', 'diluxone-users' ), '225' ),
			'HR' => array( __( 'Croatia', 'diluxone-users' ), '385' ),
			'CU' => array( __( 'Cuba', 'diluxone-users' ), '53' ),
			'CW' => array( __( 'Curacao', 'diluxone-users' ), '599' ),
			'CY' => array( __( 'Cyprus', 'diluxone-users' ), '357' ),
			'CZ' => array( __( 'Czechia', 'diluxone-users' ), '420' ),
			'DK' => array( __( 'Denmark', 'diluxone-users' ), '45' ),
			'DJ' => array( __( 'Djibouti', 'diluxone-users' ), '253' ),
			'DM' => array( __( 'Dominica', 'diluxone-users' ), '1767' ),
			'DO' => array( __( 'Dominican Republic', 'diluxone-users' ), '1809' ),
			'EC' => array( __( 'Ecuador', 'diluxone-users' ), '593' ),
			'EG' => array( __( 'Egypt', 'diluxone-users' ), '20' ),
			'SV' => array( __( 'El Salvador', 'diluxone-users' ), '503' ),
			'GQ' => array( __( 'Equatorial Guinea', 'diluxone-users' ), '240' ),
			'ER' => array( __( 'Eritrea', 'diluxone-users' ), '291' ),
			'EE' => array( __( 'Estonia', 'diluxone-users' ), '372' ),
			'SZ' => array( __( 'Eswatini', 'diluxone-users' ), '268' ),
			'ET' => array( __( 'Ethiopia', 'diluxone-users' ), '251' ),
			'FJ' => array( __( 'Fiji', 'diluxone-users' ), '679' ),
			'FI' => array( __( 'Finland', 'diluxone-users' ), '358' ),
			'FR' => array( __( 'France', 'diluxone-users' ), '33' ),
			'GA' => array( __( 'Gabon', 'diluxone-users' ), '241' ),
			'GM' => array( __( 'Gambia', 'diluxone-users' ), '220' ),
			'GE' => array( __( 'Georgia', 'diluxone-users' ), '995' ),
			'DE' => array( __( 'Germany', 'diluxone-users' ), '49' ),
			'GH' => array( __( 'Ghana', 'diluxone-users' ), '233' ),
			'GI' => array( __( 'Gibraltar', 'diluxone-users' ), '350' ),
			'GR' => array( __( 'Greece', 'diluxone-users' ), '30' ),
			'GL' => array( __( 'Greenland', 'diluxone-users' ), '299' ),
			'GD' => array( __( 'Grenada', 'diluxone-users' ), '1473' ),
			'GT' => array( __( 'Guatemala', 'diluxone-users' ), '502' ),
			'GN' => array( __( 'Guinea', 'diluxone-users' ), '224' ),
			'GW' => array( __( 'Guinea-Bissau', 'diluxone-users' ), '245' ),
			'GY' => array( __( 'Guyana', 'diluxone-users' ), '592' ),
			'HT' => array( __( 'Haiti', 'diluxone-users' ), '509' ),
			'HN' => array( __( 'Honduras', 'diluxone-users' ), '504' ),
			'HK' => array( __( 'Hong Kong', 'diluxone-users' ), '852' ),
			'HU' => array( __( 'Hungary', 'diluxone-users' ), '36' ),
			'IS' => array( __( 'Iceland', 'diluxone-users' ), '354' ),
			'IN' => array( __( 'India', 'diluxone-users' ), '91' ),
			'ID' => array( __( 'Indonesia', 'diluxone-users' ), '62' ),
			'IR' => array( __( 'Iran', 'diluxone-users' ), '98' ),
			'IQ' => array( __( 'Iraq', 'diluxone-users' ), '964' ),
			'IE' => array( __( 'Ireland', 'diluxone-users' ), '353' ),
			'IL' => array( __( 'Israel', 'diluxone-users' ), '972' ),
			'IT' => array( __( 'Italy', 'diluxone-users' ), '39' ),
			'JM' => array( __( 'Jamaica', 'diluxone-users' ), '1876' ),
			'JP' => array( __( 'Japan', 'diluxone-users' ), '81' ),
			'JO' => array( __( 'Jordan', 'diluxone-users' ), '962' ),
			'KZ' => array( __( 'Kazakhstan', 'diluxone-users' ), '7' ),
			'KE' => array( __( 'Kenya', 'diluxone-users' ), '254' ),
			'KW' => array( __( 'Kuwait', 'diluxone-users' ), '965' ),
			'KG' => array( __( 'Kyrgyzstan', 'diluxone-users' ), '996' ),
			'LA' => array( __( 'Laos', 'diluxone-users' ), '856' ),
			'LV' => array( __( 'Latvia', 'diluxone-users' ), '371' ),
			'LB' => array( __( 'Lebanon', 'diluxone-users' ), '961' ),
			'LS' => array( __( 'Lesotho', 'diluxone-users' ), '266' ),
			'LR' => array( __( 'Liberia', 'diluxone-users' ), '231' ),
			'LY' => array( __( 'Libya', 'diluxone-users' ), '218' ),
			'LI' => array( __( 'Liechtenstein', 'diluxone-users' ), '423' ),
			'LT' => array( __( 'Lithuania', 'diluxone-users' ), '370' ),
			'LU' => array( __( 'Luxembourg', 'diluxone-users' ), '352' ),
			'MO' => array( __( 'Macao', 'diluxone-users' ), '853' ),
			'MG' => array( __( 'Madagascar', 'diluxone-users' ), '261' ),
			'MW' => array( __( 'Malawi', 'diluxone-users' ), '265' ),
			'MY' => array( __( 'Malaysia', 'diluxone-users' ), '60' ),
			'MV' => array( __( 'Maldives', 'diluxone-users' ), '960' ),
			'ML' => array( __( 'Mali', 'diluxone-users' ), '223' ),
			'MT' => array( __( 'Malta', 'diluxone-users' ), '356' ),
			'MR' => array( __( 'Mauritania', 'diluxone-users' ), '222' ),
			'MU' => array( __( 'Mauritius', 'diluxone-users' ), '230' ),
			'MX' => array( __( 'Mexico', 'diluxone-users' ), '52' ),
			'MD' => array( __( 'Moldova', 'diluxone-users' ), '373' ),
			'MC' => array( __( 'Monaco', 'diluxone-users' ), '377' ),
			'MN' => array( __( 'Mongolia', 'diluxone-users' ), '976' ),
			'ME' => array( __( 'Montenegro', 'diluxone-users' ), '382' ),
			'MA' => array( __( 'Morocco', 'diluxone-users' ), '212' ),
			'MZ' => array( __( 'Mozambique', 'diluxone-users' ), '258' ),
			'MM' => array( __( 'Myanmar', 'diluxone-users' ), '95' ),
			'NA' => array( __( 'Namibia', 'diluxone-users' ), '264' ),
			'NP' => array( __( 'Nepal', 'diluxone-users' ), '977' ),
			'NL' => array( __( 'Netherlands', 'diluxone-users' ), '31' ),
			'NZ' => array( __( 'New Zealand', 'diluxone-users' ), '64' ),
			'NI' => array( __( 'Nicaragua', 'diluxone-users' ), '505' ),
			'NE' => array( __( 'Niger', 'diluxone-users' ), '227' ),
			'NG' => array( __( 'Nigeria', 'diluxone-users' ), '234' ),
			'MK' => array( __( 'North Macedonia', 'diluxone-users' ), '389' ),
			'NO' => array( __( 'Norway', 'diluxone-users' ), '47' ),
			'OM' => array( __( 'Oman', 'diluxone-users' ), '968' ),
			'PK' => array( __( 'Pakistan', 'diluxone-users' ), '92' ),
			'PS' => array( __( 'Palestine', 'diluxone-users' ), '970' ),
			'PA' => array( __( 'Panama', 'diluxone-users' ), '507' ),
			'PG' => array( __( 'Papua New Guinea', 'diluxone-users' ), '675' ),
			'PY' => array( __( 'Paraguay', 'diluxone-users' ), '595' ),
			'PE' => array( __( 'Peru', 'diluxone-users' ), '51' ),
			'PH' => array( __( 'Philippines', 'diluxone-users' ), '63' ),
			'PL' => array( __( 'Poland', 'diluxone-users' ), '48' ),
			'PT' => array( __( 'Portugal', 'diluxone-users' ), '351' ),
			'PR' => array( __( 'Puerto Rico', 'diluxone-users' ), '1787' ),
			'QA' => array( __( 'Qatar', 'diluxone-users' ), '974' ),
			'RO' => array( __( 'Romania', 'diluxone-users' ), '40' ),
			'RU' => array( __( 'Russia', 'diluxone-users' ), '7' ),
			'RW' => array( __( 'Rwanda', 'diluxone-users' ), '250' ),
			'SM' => array( __( 'San Marino', 'diluxone-users' ), '378' ),
			'SA' => array( __( 'Saudi Arabia', 'diluxone-users' ), '966' ),
			'SN' => array( __( 'Senegal', 'diluxone-users' ), '221' ),
			'RS' => array( __( 'Serbia', 'diluxone-users' ), '381' ),
			'SC' => array( __( 'Seychelles', 'diluxone-users' ), '248' ),
			'SL' => array( __( 'Sierra Leone', 'diluxone-users' ), '232' ),
			'SG' => array( __( 'Singapore', 'diluxone-users' ), '65' ),
			'SK' => array( __( 'Slovakia', 'diluxone-users' ), '421' ),
			'SI' => array( __( 'Slovenia', 'diluxone-users' ), '386' ),
			'SO' => array( __( 'Somalia', 'diluxone-users' ), '252' ),
			'ZA' => array( __( 'South Africa', 'diluxone-users' ), '27' ),
			'KR' => array( __( 'South Korea', 'diluxone-users' ), '82' ),
			'SS' => array( __( 'South Sudan', 'diluxone-users' ), '211' ),
			'ES' => array( __( 'Spain', 'diluxone-users' ), '34' ),
			'LK' => array( __( 'Sri Lanka', 'diluxone-users' ), '94' ),
			'SD' => array( __( 'Sudan', 'diluxone-users' ), '249' ),
			'SR' => array( __( 'Suriname', 'diluxone-users' ), '597' ),
			'SE' => array( __( 'Sweden', 'diluxone-users' ), '46' ),
			'CH' => array( __( 'Switzerland', 'diluxone-users' ), '41' ),
			'SY' => array( __( 'Syria', 'diluxone-users' ), '963' ),
			'TW' => array( __( 'Taiwan', 'diluxone-users' ), '886' ),
			'TJ' => array( __( 'Tajikistan', 'diluxone-users' ), '992' ),
			'TZ' => array( __( 'Tanzania', 'diluxone-users' ), '255' ),
			'TH' => array( __( 'Thailand', 'diluxone-users' ), '66' ),
			'TL' => array( __( 'Timor-Leste', 'diluxone-users' ), '670' ),
			'TG' => array( __( 'Togo', 'diluxone-users' ), '228' ),
			'TT' => array( __( 'Trinidad and Tobago', 'diluxone-users' ), '1868' ),
			'TN' => array( __( 'Tunisia', 'diluxone-users' ), '216' ),
			'TR' => array( __( 'Turkiye', 'diluxone-users' ), '90' ),
			'TM' => array( __( 'Turkmenistan', 'diluxone-users' ), '993' ),
			'UG' => array( __( 'Uganda', 'diluxone-users' ), '256' ),
			'UA' => array( __( 'Ukraine', 'diluxone-users' ), '380' ),
			'AE' => array( __( 'United Arab Emirates', 'diluxone-users' ), '971' ),
			'GB' => array( __( 'United Kingdom', 'diluxone-users' ), '44' ),
			'US' => array( __( 'United States', 'diluxone-users' ), '1' ),
			'UY' => array( __( 'Uruguay', 'diluxone-users' ), '598' ),
			'UZ' => array( __( 'Uzbekistan', 'diluxone-users' ), '998' ),
			'VE' => array( __( 'Venezuela', 'diluxone-users' ), '58' ),
			'VN' => array( __( 'Vietnam', 'diluxone-users' ), '84' ),
			'YE' => array( __( 'Yemen', 'diluxone-users' ), '967' ),
			'ZM' => array( __( 'Zambia', 'diluxone-users' ), '260' ),
			'ZW' => array( __( 'Zimbabwe', 'diluxone-users' ), '263' ),
			'AI' => array( __( 'Anguilla', 'diluxone-users' ), '1264' ),
			'AQ' => array( __( 'Antarctica', 'diluxone-users' ), '672' ),
			'AS' => array( __( 'American Samoa', 'diluxone-users' ), '1684' ),
			'AX' => array( __( 'Aland Islands', 'diluxone-users' ), '358' ),
			'BL' => array( __( 'Saint Barthelemy', 'diluxone-users' ), '590' ),
			'BQ' => array( __( 'Caribbean Netherlands', 'diluxone-users' ), '599' ),
			'CC' => array( __( 'Cocos (Keeling) Islands', 'diluxone-users' ), '61' ),
			'CK' => array( __( 'Cook Islands', 'diluxone-users' ), '682' ),
			'CX' => array( __( 'Christmas Island', 'diluxone-users' ), '61' ),
			'EH' => array( __( 'Western Sahara', 'diluxone-users' ), '212' ),
			'FK' => array( __( 'Falkland Islands', 'diluxone-users' ), '500' ),
			'FM' => array( __( 'Micronesia', 'diluxone-users' ), '691' ),
			'FO' => array( __( 'Faroe Islands', 'diluxone-users' ), '298' ),
			'GF' => array( __( 'French Guiana', 'diluxone-users' ), '594' ),
			'GG' => array( __( 'Guernsey', 'diluxone-users' ), '44' ),
			'GP' => array( __( 'Guadeloupe', 'diluxone-users' ), '590' ),
			'GS' => array( __( 'South Georgia', 'diluxone-users' ), '500' ),
			'GU' => array( __( 'Guam', 'diluxone-users' ), '1671' ),
			'IM' => array( __( 'Isle of Man', 'diluxone-users' ), '44' ),
			'IO' => array( __( 'British Indian Ocean Territory', 'diluxone-users' ), '246' ),
			'JE' => array( __( 'Jersey', 'diluxone-users' ), '44' ),
			'KI' => array( __( 'Kiribati', 'diluxone-users' ), '686' ),
			'KN' => array( __( 'Saint Kitts and Nevis', 'diluxone-users' ), '1869' ),
			'KP' => array( __( 'North Korea', 'diluxone-users' ), '850' ),
			'LC' => array( __( 'Saint Lucia', 'diluxone-users' ), '1758' ),
			'MF' => array( __( 'Saint Martin', 'diluxone-users' ), '590' ),
			'MH' => array( __( 'Marshall Islands', 'diluxone-users' ), '692' ),
			'MP' => array( __( 'Northern Mariana Islands', 'diluxone-users' ), '1670' ),
			'MQ' => array( __( 'Martinique', 'diluxone-users' ), '596' ),
			'MS' => array( __( 'Montserrat', 'diluxone-users' ), '1664' ),
			'NC' => array( __( 'New Caledonia', 'diluxone-users' ), '687' ),
			'NF' => array( __( 'Norfolk Island', 'diluxone-users' ), '672' ),
			'NR' => array( __( 'Nauru', 'diluxone-users' ), '674' ),
			'NU' => array( __( 'Niue', 'diluxone-users' ), '683' ),
			'PF' => array( __( 'French Polynesia', 'diluxone-users' ), '689' ),
			'PM' => array( __( 'Saint Pierre and Miquelon', 'diluxone-users' ), '508' ),
			'PN' => array( __( 'Pitcairn', 'diluxone-users' ), '64' ),
			'PW' => array( __( 'Palau', 'diluxone-users' ), '680' ),
			'RE' => array( __( 'Reunion', 'diluxone-users' ), '262' ),
			'SB' => array( __( 'Solomon Islands', 'diluxone-users' ), '677' ),
			'SH' => array( __( 'Saint Helena', 'diluxone-users' ), '290' ),
			'SJ' => array( __( 'Svalbard and Jan Mayen', 'diluxone-users' ), '47' ),
			'ST' => array( __( 'Sao Tome and Principe', 'diluxone-users' ), '239' ),
			'SX' => array( __( 'Sint Maarten', 'diluxone-users' ), '1721' ),
			'TC' => array( __( 'Turks and Caicos', 'diluxone-users' ), '1649' ),
			'TF' => array( __( 'French Southern Territories', 'diluxone-users' ), '262' ),
			'TK' => array( __( 'Tokelau', 'diluxone-users' ), '690' ),
			'TO' => array( __( 'Tonga', 'diluxone-users' ), '676' ),
			'TV' => array( __( 'Tuvalu', 'diluxone-users' ), '688' ),
			'UM' => array( __( 'U.S. Minor Outlying Islands', 'diluxone-users' ), '1' ),
			'VC' => array( __( 'Saint Vincent and the Grenadines', 'diluxone-users' ), '1784' ),
			'VG' => array( __( 'British Virgin Islands', 'diluxone-users' ), '1284' ),
			'VI' => array( __( 'U.S. Virgin Islands', 'diluxone-users' ), '1340' ),
			'VU' => array( __( 'Vanuatu', 'diluxone-users' ), '678' ),
			'WF' => array( __( 'Wallis and Futuna', 'diluxone-users' ), '681' ),
			'WS' => array( __( 'Samoa', 'diluxone-users' ), '685' ),
			'XK' => array( __( 'Kosovo', 'diluxone-users' ), '383' ),
			'YT' => array( __( 'Mayotte', 'diluxone-users' ), '262' ),
		);

		/**
		 * Filters the list of countries.
		 *
		 * It is there for narrowing it: a regional site does not need all two hundred.
		 * The names arrive already translated into the site's language.
		 *
		 * @param array<string, array{0: string, 1: string}> $countries ISO => [translated name, dialling code].
		 */
		$cache[ $locale ] = apply_filters( 'diluxone_users_countries', $countries );
	}

	return $cache[ $locale ];
}

/** A country's dialling code, without the "+". */
function diluxone_users_country_dial( string $iso ): string {
	$countries = diluxone_users_countries();

	return (string) ( $countries[ strtoupper( $iso ) ][1] ?? '' );
}

/**
 * The countries sorted by name, with the preferred ones first.
 *
 * Pure alphabetical order leaves the site's own country halfway down a list
 * of two hundred. The ones passed as preferred go on top, set apart.
 *
 * @param array<int, string> $first ISO codes that go first.
 * @return array<string, string> ISO => name.
 */
function diluxone_users_countries_sorted( array $first = array() ): array {
	$countries = diluxone_users_countries();
	$names     = array();

	foreach ( $countries as $iso => $data ) {
		$names[ $iso ] = $data[0];
	}

	// By name as a person reads it: an accent does not move a country to the
	// end of its letter. SORT_LOCALE_STRING would, on any server whose locale
	// is C — most of them — where "Côte d'Ivoire" came after "Cuba".
	uasort( $names, static fn( string $a, string $b ): int => strcasecmp( remove_accents( $a ), remove_accents( $b ) ) );

	$top = array();

	foreach ( $first as $iso ) {
		$iso = strtoupper( $iso );

		if ( isset( $names[ $iso ] ) ) {
			$top[ $iso ] = $names[ $iso ];
			unset( $names[ $iso ] );
		}
	}

	return $top + $names;
}
