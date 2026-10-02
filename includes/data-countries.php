<?php
/**
 * Countries, with their ISO code and their dialling code.
 *
 * It is a data file and nothing more. It exists so that nobody has to type
 * "Argentina" by hand into a text field: the "country" field type comes from
 * here, and the phone one uses the dialling code to assemble the full number.
 *
 * The ISO code is what is stored; the name is only for display. That way the
 * value does not break if the translation changes tomorrow, and filtering by
 * country needs no string comparison.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every country.
 *
 * @return array<string, array{0: string, 1: string}> ISO => [name, dialling code].
 */
function diluxone_users_countries(): array {
	static $countries = null;

	if ( null === $countries ) {
		$countries = array(
			'AF' => array( 'Afghanistan', '93' ),
			'AL' => array( 'Albania', '355' ),
			'DZ' => array( 'Algeria', '213' ),
			'AD' => array( 'Andorra', '376' ),
			'AO' => array( 'Angola', '244' ),
			'AG' => array( 'Antigua and Barbuda', '1268' ),
			'AR' => array( 'Argentina', '54' ),
			'AM' => array( 'Armenia', '374' ),
			'AW' => array( 'Aruba', '297' ),
			'AU' => array( 'Australia', '61' ),
			'AT' => array( 'Austria', '43' ),
			'AZ' => array( 'Azerbaijan', '994' ),
			'BS' => array( 'Bahamas', '1242' ),
			'BH' => array( 'Bahrain', '973' ),
			'BD' => array( 'Bangladesh', '880' ),
			'BB' => array( 'Barbados', '1246' ),
			'BY' => array( 'Belarus', '375' ),
			'BE' => array( 'Belgium', '32' ),
			'BZ' => array( 'Belize', '501' ),
			'BJ' => array( 'Benin', '229' ),
			'BM' => array( 'Bermuda', '1441' ),
			'BT' => array( 'Bhutan', '975' ),
			'BO' => array( 'Bolivia', '591' ),
			'BA' => array( 'Bosnia and Herzegovina', '387' ),
			'BW' => array( 'Botswana', '267' ),
			'BR' => array( 'Brazil', '55' ),
			'BN' => array( 'Brunei', '673' ),
			'BG' => array( 'Bulgaria', '359' ),
			'BF' => array( 'Burkina Faso', '226' ),
			'BI' => array( 'Burundi', '257' ),
			'KH' => array( 'Cambodia', '855' ),
			'CM' => array( 'Cameroon', '237' ),
			'CA' => array( 'Canada', '1' ),
			'CV' => array( 'Cape Verde', '238' ),
			'KY' => array( 'Cayman Islands', '1345' ),
			'CF' => array( 'Central African Republic', '236' ),
			'TD' => array( 'Chad', '235' ),
			'CL' => array( 'Chile', '56' ),
			'CN' => array( 'China', '86' ),
			'CO' => array( 'Colombia', '57' ),
			'KM' => array( 'Comoros', '269' ),
			'CG' => array( 'Congo', '242' ),
			'CD' => array( 'Congo (DRC)', '243' ),
			'CR' => array( 'Costa Rica', '506' ),
			'CI' => array( 'Côte d\'Ivoire', '225' ),
			'HR' => array( 'Croatia', '385' ),
			'CU' => array( 'Cuba', '53' ),
			'CW' => array( 'Curacao', '599' ),
			'CY' => array( 'Cyprus', '357' ),
			'CZ' => array( 'Czechia', '420' ),
			'DK' => array( 'Denmark', '45' ),
			'DJ' => array( 'Djibouti', '253' ),
			'DM' => array( 'Dominica', '1767' ),
			'DO' => array( 'Dominican Republic', '1809' ),
			'EC' => array( 'Ecuador', '593' ),
			'EG' => array( 'Egypt', '20' ),
			'SV' => array( 'El Salvador', '503' ),
			'GQ' => array( 'Equatorial Guinea', '240' ),
			'ER' => array( 'Eritrea', '291' ),
			'EE' => array( 'Estonia', '372' ),
			'SZ' => array( 'Eswatini', '268' ),
			'ET' => array( 'Ethiopia', '251' ),
			'FJ' => array( 'Fiji', '679' ),
			'FI' => array( 'Finland', '358' ),
			'FR' => array( 'France', '33' ),
			'GA' => array( 'Gabon', '241' ),
			'GM' => array( 'Gambia', '220' ),
			'GE' => array( 'Georgia', '995' ),
			'DE' => array( 'Germany', '49' ),
			'GH' => array( 'Ghana', '233' ),
			'GI' => array( 'Gibraltar', '350' ),
			'GR' => array( 'Greece', '30' ),
			'GL' => array( 'Greenland', '299' ),
			'GD' => array( 'Grenada', '1473' ),
			'GT' => array( 'Guatemala', '502' ),
			'GN' => array( 'Guinea', '224' ),
			'GW' => array( 'Guinea-Bissau', '245' ),
			'GY' => array( 'Guyana', '592' ),
			'HT' => array( 'Haiti', '509' ),
			'HN' => array( 'Honduras', '504' ),
			'HK' => array( 'Hong Kong', '852' ),
			'HU' => array( 'Hungary', '36' ),
			'IS' => array( 'Iceland', '354' ),
			'IN' => array( 'India', '91' ),
			'ID' => array( 'Indonesia', '62' ),
			'IR' => array( 'Iran', '98' ),
			'IQ' => array( 'Iraq', '964' ),
			'IE' => array( 'Ireland', '353' ),
			'IL' => array( 'Israel', '972' ),
			'IT' => array( 'Italy', '39' ),
			'JM' => array( 'Jamaica', '1876' ),
			'JP' => array( 'Japan', '81' ),
			'JO' => array( 'Jordan', '962' ),
			'KZ' => array( 'Kazakhstan', '7' ),
			'KE' => array( 'Kenya', '254' ),
			'KW' => array( 'Kuwait', '965' ),
			'KG' => array( 'Kyrgyzstan', '996' ),
			'LA' => array( 'Laos', '856' ),
			'LV' => array( 'Latvia', '371' ),
			'LB' => array( 'Lebanon', '961' ),
			'LS' => array( 'Lesotho', '266' ),
			'LR' => array( 'Liberia', '231' ),
			'LY' => array( 'Libya', '218' ),
			'LI' => array( 'Liechtenstein', '423' ),
			'LT' => array( 'Lithuania', '370' ),
			'LU' => array( 'Luxembourg', '352' ),
			'MO' => array( 'Macao', '853' ),
			'MG' => array( 'Madagascar', '261' ),
			'MW' => array( 'Malawi', '265' ),
			'MY' => array( 'Malaysia', '60' ),
			'MV' => array( 'Maldives', '960' ),
			'ML' => array( 'Mali', '223' ),
			'MT' => array( 'Malta', '356' ),
			'MR' => array( 'Mauritania', '222' ),
			'MU' => array( 'Mauritius', '230' ),
			'MX' => array( 'Mexico', '52' ),
			'MD' => array( 'Moldova', '373' ),
			'MC' => array( 'Monaco', '377' ),
			'MN' => array( 'Mongolia', '976' ),
			'ME' => array( 'Montenegro', '382' ),
			'MA' => array( 'Morocco', '212' ),
			'MZ' => array( 'Mozambique', '258' ),
			'MM' => array( 'Myanmar', '95' ),
			'NA' => array( 'Namibia', '264' ),
			'NP' => array( 'Nepal', '977' ),
			'NL' => array( 'Netherlands', '31' ),
			'NZ' => array( 'New Zealand', '64' ),
			'NI' => array( 'Nicaragua', '505' ),
			'NE' => array( 'Niger', '227' ),
			'NG' => array( 'Nigeria', '234' ),
			'MK' => array( 'North Macedonia', '389' ),
			'NO' => array( 'Norway', '47' ),
			'OM' => array( 'Oman', '968' ),
			'PK' => array( 'Pakistan', '92' ),
			'PS' => array( 'Palestine', '970' ),
			'PA' => array( 'Panama', '507' ),
			'PG' => array( 'Papua New Guinea', '675' ),
			'PY' => array( 'Paraguay', '595' ),
			'PE' => array( 'Peru', '51' ),
			'PH' => array( 'Philippines', '63' ),
			'PL' => array( 'Poland', '48' ),
			'PT' => array( 'Portugal', '351' ),
			'PR' => array( 'Puerto Rico', '1787' ),
			'QA' => array( 'Qatar', '974' ),
			'RO' => array( 'Romania', '40' ),
			'RU' => array( 'Russia', '7' ),
			'RW' => array( 'Rwanda', '250' ),
			'SM' => array( 'San Marino', '378' ),
			'SA' => array( 'Saudi Arabia', '966' ),
			'SN' => array( 'Senegal', '221' ),
			'RS' => array( 'Serbia', '381' ),
			'SC' => array( 'Seychelles', '248' ),
			'SL' => array( 'Sierra Leone', '232' ),
			'SG' => array( 'Singapore', '65' ),
			'SK' => array( 'Slovakia', '421' ),
			'SI' => array( 'Slovenia', '386' ),
			'SO' => array( 'Somalia', '252' ),
			'ZA' => array( 'South Africa', '27' ),
			'KR' => array( 'South Korea', '82' ),
			'SS' => array( 'South Sudan', '211' ),
			'ES' => array( 'Spain', '34' ),
			'LK' => array( 'Sri Lanka', '94' ),
			'SD' => array( 'Sudan', '249' ),
			'SR' => array( 'Suriname', '597' ),
			'SE' => array( 'Sweden', '46' ),
			'CH' => array( 'Switzerland', '41' ),
			'SY' => array( 'Syria', '963' ),
			'TW' => array( 'Taiwan', '886' ),
			'TJ' => array( 'Tajikistan', '992' ),
			'TZ' => array( 'Tanzania', '255' ),
			'TH' => array( 'Thailand', '66' ),
			'TL' => array( 'Timor-Leste', '670' ),
			'TG' => array( 'Togo', '228' ),
			'TT' => array( 'Trinidad and Tobago', '1868' ),
			'TN' => array( 'Tunisia', '216' ),
			'TR' => array( 'Turkiye', '90' ),
			'TM' => array( 'Turkmenistan', '993' ),
			'UG' => array( 'Uganda', '256' ),
			'UA' => array( 'Ukraine', '380' ),
			'AE' => array( 'United Arab Emirates', '971' ),
			'GB' => array( 'United Kingdom', '44' ),
			'US' => array( 'United States', '1' ),
			'UY' => array( 'Uruguay', '598' ),
			'UZ' => array( 'Uzbekistan', '998' ),
			'VE' => array( 'Venezuela', '58' ),
			'VN' => array( 'Vietnam', '84' ),
			'YE' => array( 'Yemen', '967' ),
			'ZM' => array( 'Zambia', '260' ),
			'ZW' => array( 'Zimbabwe', '263' ),
			'AI' => array( 'Anguilla', '1264' ),
			'AQ' => array( 'Antarctica', '672' ),
			'AS' => array( 'American Samoa', '1684' ),
			'AX' => array( 'Aland Islands', '358' ),
			'BL' => array( 'Saint Barthelemy', '590' ),
			'BQ' => array( 'Caribbean Netherlands', '599' ),
			'CC' => array( 'Cocos (Keeling) Islands', '61' ),
			'CK' => array( 'Cook Islands', '682' ),
			'CX' => array( 'Christmas Island', '61' ),
			'EH' => array( 'Western Sahara', '212' ),
			'FK' => array( 'Falkland Islands', '500' ),
			'FM' => array( 'Micronesia', '691' ),
			'FO' => array( 'Faroe Islands', '298' ),
			'GF' => array( 'French Guiana', '594' ),
			'GG' => array( 'Guernsey', '44' ),
			'GP' => array( 'Guadeloupe', '590' ),
			'GS' => array( 'South Georgia', '500' ),
			'GU' => array( 'Guam', '1671' ),
			'IM' => array( 'Isle of Man', '44' ),
			'IO' => array( 'British Indian Ocean Territory', '246' ),
			'JE' => array( 'Jersey', '44' ),
			'KI' => array( 'Kiribati', '686' ),
			'KN' => array( 'Saint Kitts and Nevis', '1869' ),
			'KP' => array( 'North Korea', '850' ),
			'LC' => array( 'Saint Lucia', '1758' ),
			'MF' => array( 'Saint Martin', '590' ),
			'MH' => array( 'Marshall Islands', '692' ),
			'MP' => array( 'Northern Mariana Islands', '1670' ),
			'MQ' => array( 'Martinique', '596' ),
			'MS' => array( 'Montserrat', '1664' ),
			'NC' => array( 'New Caledonia', '687' ),
			'NF' => array( 'Norfolk Island', '672' ),
			'NR' => array( 'Nauru', '674' ),
			'NU' => array( 'Niue', '683' ),
			'PF' => array( 'French Polynesia', '689' ),
			'PM' => array( 'Saint Pierre and Miquelon', '508' ),
			'PN' => array( 'Pitcairn', '64' ),
			'PW' => array( 'Palau', '680' ),
			'RE' => array( 'Reunion', '262' ),
			'SB' => array( 'Solomon Islands', '677' ),
			'SH' => array( 'Saint Helena', '290' ),
			'SJ' => array( 'Svalbard and Jan Mayen', '47' ),
			'ST' => array( 'Sao Tome and Principe', '239' ),
			'SX' => array( 'Sint Maarten', '1721' ),
			'TC' => array( 'Turks and Caicos', '1649' ),
			'TF' => array( 'French Southern Territories', '262' ),
			'TK' => array( 'Tokelau', '690' ),
			'TO' => array( 'Tonga', '676' ),
			'TV' => array( 'Tuvalu', '688' ),
			'UM' => array( 'U.S. Minor Outlying Islands', '1' ),
			'VC' => array( 'Saint Vincent and the Grenadines', '1784' ),
			'VG' => array( 'British Virgin Islands', '1284' ),
			'VI' => array( 'U.S. Virgin Islands', '1340' ),
			'VU' => array( 'Vanuatu', '678' ),
			'WF' => array( 'Wallis and Futuna', '681' ),
			'WS' => array( 'Samoa', '685' ),
			'XK' => array( 'Kosovo', '383' ),
			'YT' => array( 'Mayotte', '262' ),
		);

		/**
		 * Filters the list of countries.
		 *
		 * It is there for narrowing it: a regional site does not need all two hundred.
		 *
		 * @param array<string, array{0: string, 1: string}> $countries
		 */
		$countries = apply_filters( 'diluxone_users_countries', $countries );
	}

	return $countries;
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
