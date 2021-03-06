<?php

namespace App\TwStats\Utility;

class Countries
{
    const COUNTRIES = [
        /* custom */
        -1 => [
            'code' => 'default',
            'name' => 'none'
        ],
        901 => [
            'code' => 'XEN',
            'name' => 'England'
        ],
        902 => [
            'code' => 'XNI',
            'name' => 'Northern Ireland'
        ],
        903 => [
            'code' => 'XSC',
            'name' => 'Scotland'
        ],
        904 => [
            'code' => 'XWA',
            'name' => 'Wales'
        ],
        /* ISO 3166-1 */
        4 => [
            'code' => 'AF',
            'name' => 'Afghanistan'
        ],
        248 => [
            'code' => 'AX',
            'name' => 'Åland Islands'
        ],
        8 => [
            'code' => 'AL',
            'name' => 'Albania'
        ],
        12 => [
            'code' => 'DZ',
            'name' => 'Algeria'
        ],
        16 => [
            'code' => 'AS',
            'name' => 'American Samoa'
        ],
        20 => [
            'code' => 'AD',
            'name' => 'Andorra'
        ],
        24 => [
            'code' => 'AO',
            'name' => 'Angola'
        ],
        660 => [
            'code' => 'AI',
            'name' => 'Anguilla'
        ],
        10 => [
            'code' => 'AQ',
            'name' => 'Antarctica'
        ],
        28 => [
            'code' => 'AG',
            'name' => 'Antigua and Barbuda'
        ],
        032 => [
            'code' => 'AR',
            'name' => 'Argentina'
        ],
        051 => [
            'code' => 'AM',
            'name' => 'Armenia'
        ],
        533 => [
            'code' => 'AW',
            'name' => 'Aruba'
        ],
        36 => [
            'code' => 'AU',
            'name' => 'Australia'
        ],
        40 => [
            'code' => 'AT',
            'name' => 'Austria'
        ],
        31 => [
            'code' => 'AZ',
            'name' => 'Azerbaijan'
        ],
        44 => [
            'code' => 'BS',
            'name' => 'Bahamas'
        ],
        48 => [
            'code' => 'BH',
            'name' => 'Bahrain'
        ],
        50 => [
            'code' => 'BD',
            'name' => 'Bangladesh'
        ],
        52 => [
            'code' => 'BB',
            'name' => 'Barbados'
        ],
        112 => [
            'code' => 'BY',
            'name' => 'Belarus'
        ],
        56 => [
            'code' => 'BE',
            'name' => 'Belgium'
        ],
        84 => [
            'code' => 'BZ',
            'name' => 'Belize'
        ],
        204 => [
            'code' => 'BJ',
            'name' => 'Benin'
        ],
        60 => [
            'code' => 'BM',
            'name' => 'Bermuda'
        ],
        64 => [
            'code' => 'BT',
            'name' => 'Bhutan'
        ],
        68 => [
            'code' => 'BO',
            'name' => 'Bolivia'
        ],
        535 => [
            'code' => 'BQ',
            'name' => 'Bonaire'
        ],
        70 => [
            'code' => 'BA',
            'name' => 'Bosnia and Herzegovina'
        ],
        72 => [
            'code' => 'BW',
            'name' => 'Botswana'
        ],
        74 => [
            'code' => 'BV',
            'name' => 'Bouvet Island'
        ],
        76 => [
            'code' => 'BR',
            'name' => 'Brazil'
        ],
        86 => [
            'code' => 'IO',
            'name' => 'British Indian Ocean Territory'
        ],
        96 => [
            'code' => 'BN',
            'name' => 'Brunei Darussalam'
        ],
        100 => [
            'code' => 'BG',
            'name' => 'Bulgaria'
        ],
        854 => [
            'code' => 'BF',
            'name' => 'Burkina Faso'
        ],
        108 => [
            'code' => 'BI',
            'name' => 'Burundi'
        ],
        116 => [
            'code' => 'KH',
            'name' => 'Cambodia'
        ],
        120 => [
            'code' => 'CM',
            'name' => 'Cameroon'
        ],
        124 => [
            'code' => 'CA',
            'name' => 'Canada'
        ],
        132 => [
            'code' => 'CV',
            'name' => 'Cape Verde'
        ],
        136 => [
            'code' => 'KY',
            'name' => 'Cayman Islands'
        ],
        140 => [
            'code' => 'CF',
            'name' => 'Central African Republic'
        ],
        148 => [
            'code' => 'TD',
            'name' => 'Chad'
        ],
        152 => [
            'code' => 'CL',
            'name' => 'Chile'
        ],
        156 => [
            'code' => 'CN',
            'name' => 'China'
        ],
        162 => [
            'code' => 'CX',
            'name' => 'Christmas Island'
        ],
        166 => [
            'code' => 'CC',
            'name' => 'Cocos (Keeling) Islands'
        ],
        170 => [
            'code' => 'CO',
            'name' => 'Colombia'
        ],
        174 => [
            'code' => 'KM',
            'name' => 'Comoros'
        ],
        178 => [
            'code' => 'CG',
            'name' => 'Congo'
        ],
        180 => [
            'code' => 'CD',
            'name' => 'Democratic Republic of Congo'
        ],
        184 => [
            'code' => 'CK',
            'name' => 'Cook Islands'
        ],
        188 => [
            'code' => 'CR',
            'name' => 'Costa Rica'
        ],
        384 => [
            'code' => 'CI',
            'name' => 'Côte d\'Ivoire'
        ],
        191 => [
            'code' => 'HR',
            'name' => 'Croatia'
        ],
        192 => [
            'code' => 'CU',
            'name' => 'Cuba'
        ],
        531 => [
            'code' => 'CW',
            'name' => 'Curaçao'
        ],
        196 => [
            'code' => 'CY',
            'name' => 'Cyprus'
        ],
        203 => [
            'code' => 'CZ',
            'name' => 'Czech Republic'
        ],
        208 => [
            'code' => 'DK',
            'name' => 'Denmark'
        ],
        262 => [
            'code' => 'DJ',
            'name' => 'Djibouti'
        ],
        212 => [
            'code' => 'DM',
            'name' => 'Dominica'
        ],
        214 => [
            'code' => 'DO',
            'name' => 'Dominican Republic'
        ],
        218 => [
            'code' => 'EC',
            'name' => 'Ecuador'
        ],
        818 => [
            'code' => 'EG',
            'name' => 'Egypt'
        ],
        222 => [
            'code' => 'SV',
            'name' => 'El Salvador'
        ],
        226 => [
            'code' => 'GQ',
            'name' => 'Equatorial Guinea'
        ],
        232 => [
            'code' => 'ER',
            'name' => 'Eritrea'
        ],
        233 => [
            'code' => 'EE',
            'name' => 'Estonia'
        ],
        231 => [
            'code' => 'ET',
            'name' => 'Ethiopia'
        ],
        238 => [
            'code' => 'FK',
            'name' => 'Falkland Islands (Malvinas)'
        ],
        234 => [
            'code' => 'FO',
            'name' => 'Faroe Islands'
        ],
        242 => [
            'code' => 'FJ',
            'name' => 'Fiji'
        ],
        246 => [
            'code' => 'FI',
            'name' => 'Finland'
        ],
        250 => [
            'code' => 'FR',
            'name' => 'France'
        ],
        254 => [
            'code' => 'GF',
            'name' => 'French Guiana'
        ],
        258 => [
            'code' => 'PF',
            'name' => 'French Polynesia'
        ],
        260 => [
            'code' => 'TF',
            'name' => 'French Southern Territories'
        ],
        266 => [
            'code' => 'GA',
            'name' => 'Gabon'
        ],
        270 => [
            'code' => 'GM',
            'name' => 'Gambia'
        ],
        268 => [
            'code' => 'GE',
            'name' => 'Georgia'
        ],
        276 => [
            'code' => 'DE',
            'name' => 'Germany'
        ],
        288 => [
            'code' => 'GH',
            'name' => 'Ghana'
        ],
        292 => [
            'code' => 'GI',
            'name' => 'Gibraltar'
        ],
        300 => [
            'code' => 'GR',
            'name' => 'Greece'
        ],
        304 => [
            'code' => 'GL',
            'name' => 'Greenland'
        ],
        308 => [
            'code' => 'GD',
            'name' => 'Grenada'
        ],
        312 => [
            'code' => 'GP',
            'name' => 'Guadeloupe'
        ],
        316 => [
            'code' => 'GU',
            'name' => 'Guam'
        ],
        320 => [
            'code' => 'GT',
            'name' => 'Guatemala'
        ],
        831 => [
            'code' => 'GG',
            'name' => 'Guernsey'
        ],
        324 => [
            'code' => 'GN',
            'name' => 'Guinea'
        ],
        624 => [
            'code' => 'GW',
            'name' => 'Guinea-Bissau'
        ],
        328 => [
            'code' => 'GY',
            'name' => 'Guyana'
        ],
        332 => [
            'code' => 'HT',
            'name' => 'Haiti'
        ],
        334 => [
            'code' => 'HM',
            'name' => 'Heard Island and McDonald Islands'
        ],
        336 => [
            'code' => 'VA',
            'name' => 'Holy See (Vatican City State)'
        ],
        340 => [
            'code' => 'HN',
            'name' => 'Honduras'
        ],
        344 => [
            'code' => 'HK',
            'name' => 'Hong Kong'
        ],
        348 => [
            'code' => 'HU',
            'name' => 'Hungary'
        ],
        352 => [
            'code' => 'IS',
            'name' => 'Iceland'
        ],
        356 => [
            'code' => 'IN',
            'name' => 'India'
        ],
        360 => [
            'code' => 'ID',
            'name' => 'Indonesia'
        ],
        364 => [
            'code' => 'IR',
            'name' => 'Islamic Republic of Iran'
        ],
        368 => [
            'code' => 'IQ',
            'name' => 'Iraq'
        ],
        372 => [
            'code' => 'IE',
            'name' => 'Ireland'
        ],
        833 => [
            'code' => 'IM',
            'name' => 'Isle of Man'
        ],
        376 => [
            'code' => 'IL',
            'name' => 'Israel'
        ],
        380 => [
            'code' => 'IT',
            'name' => 'Italy'
        ],
        388 => [
            'code' => 'JM',
            'name' => 'Jamaica'
        ],
        392 => [
            'code' => 'JP',
            'name' => 'Japan'
        ],
        832 => [
            'code' => 'JE',
            'name' => 'Jersey'
        ],
        400 => [
            'code' => 'JO',
            'name' => 'Jordan'
        ],
        398 => [
            'code' => 'KZ',
            'name' => 'Kazakhstan'
        ],
        404 => [
            'code' => 'KE',
            'name' => 'Kenya'
        ],
        296 => [
            'code' => 'KI',
            'name' => 'Kiribati'
        ],
        408 => [
            'code' => 'KP',
            'name' => 'North Korea'
        ],
        410 => [
            'code' => 'KR',
            'name' => 'South Korea'
        ],
        414 => [
            'code' => 'KW',
            'name' => 'Kuwait'
        ],
        417 => [
            'code' => 'KG',
            'name' => 'Kyrgyzstan'
        ],
        418 => [
            'code' => 'LA',
            'name' => 'Laos'
        ],
        428 => [
            'code' => 'LV',
            'name' => 'Latvia'
        ],
        422 => [
            'code' => 'LB',
            'name' => 'Lebanon'
        ],
        426 => [
            'code' => 'LS',
            'name' => 'Lesotho'
        ],
        430 => [
            'code' => 'LR',
            'name' => 'Liberia'
        ],
        434 => [
            'code' => 'LY',
            'name' => 'Libya'
        ],
        438 => [
            'code' => 'LI',
            'name' => 'Liechtenstein'
        ],
        440 => [
            'code' => 'LT',
            'name' => 'Lithuania'
        ],
        442 => [
            'code' => 'LU',
            'name' => 'Luxembourg'
        ],
        446 => [
            'code' => 'MO',
            'name' => 'Macao'
        ],
        807 => [
            'code' => 'MK',
            'name' => 'Macedonia'
        ],
        450 => [
            'code' => 'MG',
            'name' => 'Madagascar'
        ],
        454 => [
            'code' => 'MW',
            'name' => 'Malawi'
        ],
        458 => [
            'code' => 'MY',
            'name' => 'Malaysia'
        ],
        462 => [
            'code' => 'MV',
            'name' => 'Maldives'
        ],
        466 => [
            'code' => 'ML',
            'name' => 'Mali'
        ],
        470 => [
            'code' => 'MT',
            'name' => 'Malta'
        ],
        584 => [
            'code' => 'MH',
            'name' => 'Marshall Islands'
        ],
        474 => [
            'code' => 'MQ',
            'name' => 'Martinique'
        ],
        478 => [
            'code' => 'MR',
            'name' => 'Mauritania'
        ],
        480 => [
            'code' => 'MU',
            'name' => 'Mauritius'
        ],
        175 => [
            'code' => 'YT',
            'name' => 'Mayotte'
        ],
        484 => [
            'code' => 'MX',
            'name' => 'Mexico'
        ],
        583 => [
            'code' => 'FM',
            'name' => 'Micronesia'
        ],
        498 => [
            'code' => 'MD',
            'name' => 'Moldova'
        ],
        492 => [
            'code' => 'MC',
            'name' => 'Monaco'
        ],
        496 => [
            'code' => 'MN',
            'name' => 'Mongolia'
        ],
        499 => [
            'code' => 'ME',
            'name' => 'Montenegro'
        ],
        500 => [
            'code' => 'MS',
            'name' => 'Montserrat'
        ],
        504 => [
            'code' => 'MA',
            'name' => 'Morocco'
        ],
        508 => [
            'code' => 'MZ',
            'name' => 'Mozambique'
        ],
        104 => [
            'code' => 'MM',
            'name' => 'Myanmar'
        ],
        516 => [
            'code' => 'NA',
            'name' => 'Namibia'
        ],
        520 => [
            'code' => 'NR',
            'name' => 'Nauru'
        ],
        524 => [
            'code' => 'NP',
            'name' => 'Nepal'
        ],
        528 => [
            'code' => 'NL',
            'name' => 'Netherlands'
        ],
        540 => [
            'code' => 'NC',
            'name' => 'New Caledonia'
        ],
        554 => [
            'code' => 'NZ',
            'name' => 'New Zealand'
        ],
        558 => [
            'code' => 'NI',
            'name' => 'Nicaragua'
        ],
        562 => [
            'code' => 'NE',
            'name' => 'Niger'
        ],
        566 => [
            'code' => 'NG',
            'name' => 'Nigeria'
        ],
        570 => [
            'code' => 'NU',
            'name' => 'Niue'
        ],
        574 => [
            'code' => 'NF',
            'name' => 'Norfolk Island'
        ],
        580 => [
            'code' => 'MP',
            'name' => 'Northern Mariana Islands'
        ],
        578 => [
            'code' => 'NO',
            'name' => 'Norway'
        ],
        512 => [
            'code' => 'OM',
            'name' => 'Oman'
        ],
        586 => [
            'code' => 'PK',
            'name' => 'Pakistan'
        ],
        585 => [
            'code' => 'PW',
            'name' => 'Palau'
        ],
        275 => [
            'code' => 'PS',
            'name' => 'Palestine'
        ],
        591 => [
            'code' => 'PA',
            'name' => 'Panama'
        ],
        598 => [
            'code' => 'PG',
            'name' => 'Papua New Guinea'
        ],
        600 => [
            'code' => 'PY',
            'name' => 'Paraguay'
        ],
        604 => [
            'code' => 'PE',
            'name' => 'Peru'
        ],
        608 => [
            'code' => 'PH',
            'name' => 'Philippines'
        ],
        612 => [
            'code' => 'PN',
            'name' => 'Pitcairn'
        ],
        616 => [
            'code' => 'PL',
            'name' => 'Poland'
        ],
        620 => [
            'code' => 'PT',
            'name' => 'Portugal'
        ],
        630 => [
            'code' => 'PR',
            'name' => 'Puerto Rico'
        ],
        634 => [
            'code' => 'QA',
            'name' => 'Qatar'
        ],
        638 => [
            'code' => 'RE',
            'name' => 'Réunion'
        ],
        642 => [
            'code' => 'RO',
            'name' => 'Romania'
        ],
        643 => [
            'code' => 'RU',
            'name' => 'Russian Federation'
        ],
        646 => [
            'code' => 'RW',
            'name' => 'Rwanda'
        ],
        652 => [
            'code' => 'BL',
            'name' => 'Saint Barthélemy'
        ],
        654 => [
            'code' => 'SH',
            'name' => 'Saint Helena and Dependencies'
        ],
        659 => [
            'code' => 'KN',
            'name' => 'Saint Kitts and Nevis'
        ],
        662 => [
            'code' => 'LC',
            'name' => 'Saint Lucia'
        ],
        663 => [
            'code' => 'MF',
            'name' => 'Saint Martin (French part)'
        ],
        666 => [
            'code' => 'PM',
            'name' => 'Saint Pierre and Miquelon'
        ],
        670 => [
            'code' => 'VC',
            'name' => 'Saint Vincent and the Grenadines'
        ],
        882 => [
            'code' => 'WS',
            'name' => 'Samoa'
        ],
        674 => [
            'code' => 'SM',
            'name' => 'San Marino'
        ],
        678 => [
            'code' => 'ST',
            'name' => 'Sao Tome and Principe'
        ],
        682 => [
            'code' => 'SA',
            'name' => 'Saudi Arabia'
        ],
        686 => [
            'code' => 'SN',
            'name' => 'Senegal'
        ],
        688 => [
            'code' => 'RS',
            'name' => 'Serbia'
        ],
        690 => [
            'code' => 'SC',
            'name' => 'Seychelles'
        ],
        694 => [
            'code' => 'SL',
            'name' => 'Sierra Leone'
        ],
        702 => [
            'code' => 'SG',
            'name' => 'Singapore'
        ],
        534 => [
            'code' => 'SX',
            'name' => 'Sint Maarten (Dutch part)'
        ],
        703 => [
            'code' => 'SK',
            'name' => 'Slovakia'
        ],
        705 => [
            'code' => 'SI',
            'name' => 'Slovenia'
        ],
        90 => [
            'code' => 'SB',
            'name' => 'Solomon Islands'
        ],
        706 => [
            'code' => 'SO',
            'name' => 'Somalia'
        ],
        710 => [
            'code' => 'ZA',
            'name' => 'South Africa'
        ],
        239 => [
            'code' => 'GS',
            'name' => 'South Georgia and the South Sandwich Islands'
        ],
        728 => [
            'code' => 'SS',
            'name' => 'South Sudan'
        ],
        724 => [
            'code' => 'ES',
            'name' => 'Spain'
        ],
        144 => [
            'code' => 'LK',
            'name' => 'Sri Lanka'
        ],
        729 => [
            'code' => 'SD',
            'name' => 'Sudan'
        ],
        740 => [
            'code' => 'SR',
            'name' => 'Suriname'
        ],
        744 => [
            'code' => 'SJ',
            'name' => 'Svalbard and Jan Mayen'
        ],
        748 => [
            'code' => 'SZ',
            'name' => 'Swaziland'
        ],
        752 => [
            'code' => 'SE',
            'name' => 'Sweden'
        ],
        756 => [
            'code' => 'CH',
            'name' => 'Switzerland'
        ],
        760 => [
            'code' => 'SY',
            'name' => 'Syrian Arab Republic'
        ],
        158 => [
            'code' => 'TW',
            'name' => 'Taiwan, Province of China'
        ],
        762 => [
            'code' => 'TJ',
            'name' => 'Tajikistan'
        ],
        834 => [
            'code' => 'TZ',
            'name' => 'Tanzania, United Republic of'
        ],
        764 => [
            'code' => 'TH',
            'name' => 'Thailand'
        ],
        626 => [
            'code' => 'TL',
            'name' => 'Timor-Leste'
        ],
        768 => [
            'code' => 'TG',
            'name' => 'Togo'
        ],
        772 => [
            'code' => 'TK',
            'name' => 'Tokelau'
        ],
        776 => [
            'code' => 'TO',
            'name' => 'Tonga'
        ],
        780 => [
            'code' => 'TT',
            'name' => 'Trinidad and Tobago'
        ],
        788 => [
            'code' => 'TN',
            'name' => 'Tunisia'
        ],
        792 => [
            'code' => 'TR',
            'name' => 'Turkey'
        ],
        795 => [
            'code' => 'TM',
            'name' => 'Turkmenistan'
        ],
        796 => [
            'code' => 'TC',
            'name' => 'Turks and Caicos Islands'
        ],
        798 => [
            'code' => 'TV',
            'name' => 'Tuvalu'
        ],
        800 => [
            'code' => 'UG',
            'name' => 'Uganda'
        ],
        804 => [
            'code' => 'UA',
            'name' => 'Ukraine'
        ],
        784 => [
            'code' => 'AE',
            'name' => 'United Arab Emirates'
        ],
        826 => [
            'code' => 'GB',
            'name' => 'United Kingdom'
        ],
        840 => [
            'code' => 'US',
            'name' => 'United States'
        ],
        581 => [
            'code' => 'UM',
            'name' => 'United States Minor Outlying Islands'
        ],
        858 => [
            'code' => 'UY',
            'name' => 'Uruguay'
        ],
        860 => [
            'code' => 'UZ',
            'name' => 'Uzbekistan'
        ],
        548 => [
            'code' => 'VU',
            'name' => 'Vanuatu'
        ],
        862 => [
            'code' => 'VE',
            'name' => 'Venezuela, Bolivarian Republic of'
        ],
        704 => [
            'code' => 'VN',
            'name' => 'Viet Nam'
        ],
        92 => [
            'code' => 'VG',
            'name' => 'Virgin Islands, British'
        ],
        850 => [
            'code' => 'VI',
            'name' => 'Virgin Islands, U.S.'
        ],
        876 => [
            'code' => 'WF',
            'name' => 'Wallis and Futuna'
        ],
        732 => [
            'code' => 'EH',
            'name' => 'Western Sahara'
        ],
        887 => [
            'code' => 'YE',
            'name' => 'Yemen'
        ],
        894 => [
            'code' => 'ZM',
            'name' => 'Zambia'
        ],
        716 => [
            'code' => 'ZW',
            'name' => 'Zimbabwe'
        ],
    ];

    /**
     * retrieve the array with the country name and country code based on the country id
     *
     * @param $number
     * @return array
     */
    public static function getCountry($number)
    {
        $number = (int)$number;
        return isset(self::COUNTRIES[$number]) ? self::COUNTRIES[$number] :self::COUNTRIES[-1];
    }

    /**
     * retrieve the country code based on the country id
     *
     * @param $number
     * @return string
     */
    public static function getCountryCode($number)
    {
        $number = (int)$number;
        return isset(self::COUNTRIES[$number]) ? self::COUNTRIES[$number]['code'] : self::COUNTRIES[-1]['code'];
    }

    /**
     * retrieve the country name based on the country id
     *
     * @param $number
     * @return string
     */
    public static function getCountryName($number)
    {
        $number = (int)$number;
        return isset(self::COUNTRIES[$number]) ? self::COUNTRIES[$number]['name'] : self::COUNTRIES[-1]['name'];
    }
}