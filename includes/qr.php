<?php
/**
 * A QR code, in SVG, with no dependencies.
 *
 * It is needed for exactly one thing: showing the `otpauth://` URI so the
 * person can scan it with their authenticator app. Pulling in a whole library
 * — or worse, sending the URI to an external service that generates the
 * image, which is what several plugins do and means leaking the second-factor
 * secret to a third party — is out of all proportion.
 *
 * The scope is deliberate: byte mode, correction level L, versions 1 to 10
 * and a fixed mask. That fits up to 271 bytes, which is plenty for a
 * TOTP URI, and avoids half the standard. The fixed mask is legal: the
 * standard requires the format information to say which one was used, not
 * that the best one be chosen.
 *
 * Everything that follows is ISO/IEC 18004. The tables are the standard's.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * How many data bytes fit per version, in byte mode and level L.
 *
 * @return array<int, int>
 */
function diluxone_users_qr_capacity(): array {
	return array(
		1  => 17,
		2  => 32,
		3  => 53,
		4  => 78,
		5  => 106,
		6  => 134,
		7  => 154,
		8  => 192,
		9  => 230,
		10 => 271,
	);
}

/**
 * Per version: [ error-correction codewords per block, group 1 blocks,
 * data codewords per group 1 block, group 2 blocks, group 2 codewords ].
 * Level L.
 *
 * @return array<int, array<int, int>>
 */
function diluxone_users_qr_blocks(): array {
	return array(
		1  => array( 7, 1, 19, 0, 0 ),
		2  => array( 10, 1, 34, 0, 0 ),
		3  => array( 15, 1, 55, 0, 0 ),
		4  => array( 20, 1, 80, 0, 0 ),
		5  => array( 26, 1, 108, 0, 0 ),
		6  => array( 18, 2, 68, 0, 0 ),
		7  => array( 20, 2, 78, 0, 0 ),
		8  => array( 24, 2, 97, 0, 0 ),
		9  => array( 30, 2, 116, 0, 0 ),
		10 => array( 18, 2, 68, 2, 69 ),
	);
}

/**
 * Where the alignment patterns go, per version.
 *
 * @return array<int, array<int, int>>
 */
function diluxone_users_qr_alignment(): array {
	return array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);
}

/**
 * The version information, for 7 onwards.
 *
 * They are 18 bits worked out with a BCH that there is no need to implement:
 * there are four values and they are in the standard.
 *
 * @return array<int, string>
 */
function diluxone_users_qr_version_info(): array {
	return array(
		7  => '000111110010010100',
		8  => '001000010110111100',
		9  => '001001101010011001',
		10 => '001010010011010011',
	);
}

/**
 * The format information for level L and mask 0.
 *
 * Only one, because the level and the mask are fixed. It also comes from the
 * standard.
 */
const DILUXONE_USERS_QR_FORMAT = '111011111000100';

/* ── Reed-Solomon over GF(256) ─────────────────────────────────────── */

/**
 * The field's exponent and logarithm tables, worked out once.
 *
 * @return array<int, array<int, int>>
 */
function diluxone_users_qr_gf(): array {
	static $tables = null;

	if ( null !== $tables ) {
		return $tables;
	}

	$exp = array_fill( 0, 512, 0 );
	$log = array_fill( 0, 256, 0 );
	$x   = 1;

	for ( $i = 0; $i < 255; $i++ ) {
		$exp[ $i ] = $x;
		$log[ $x ] = $i;
		$x       <<= 1;

			// The QR primitive polynomial is 0x11D.
		if ( $x & 0x100 ) {
			$x ^= 0x11D;
		}
	}

	for ( $i = 255; $i < 512; $i++ ) {
		$exp[ $i ] = $exp[ $i - 255 ];
	}

	$tables = array( $exp, $log );

	return $tables;
}

/**
 * The generator polynomial for n correction codewords.
 *
 * @return array<int, int>
 */
function diluxone_users_qr_generator( int $n ): array {
	[ $exp, $log ] = diluxone_users_qr_gf();

	$poly = array( 1 );

	for ( $i = 0; $i < $n; $i++ ) {
		$next = array_fill( 0, count( $poly ) + 1, 0 );

		foreach ( $poly as $j => $coef ) {
			$next[ $j ] ^= $coef;

			if ( 0 !== $coef ) {
				$next[ $j + 1 ] ^= $exp[ ( $log[ $coef ] + $i ) % 255 ];
			}
		}

		$poly = $next;
	}

	return $poly;
}

/**
 * The error-correction codewords of a data block.
 *
 * @param array<int, float|int> $data
 * @return array<int, float|int>
 */
function diluxone_users_qr_ec( array $data, int $n ): array {
	[ $exp, $log ] = diluxone_users_qr_gf();

	$gen  = diluxone_users_qr_generator( $n );
	$rest = array_merge( $data, array_fill( 0, $n, 0 ) );

	$length = count( $data );

	for ( $i = 0; $i < $length; $i++ ) {
		$coef = $rest[ $i ];

		if ( 0 === $coef ) {
			continue;
		}

		foreach ( $gen as $j => $g ) {
			if ( 0 !== $g ) {
				$rest[ $i + $j ] ^= $exp[ ( $log[ $g ] + $log[ $coef ] ) % 255 ];
			}
		}
	}

	return array_slice( $rest, count( $data ) );
}

/* ── The matrix ────────────────────────────────────────────────────── */

/**
 * The module matrix of a text: true = black.
 *
 * @return array<int, array<int, bool>>
 */
function diluxone_users_qr_matrix( string $text ): ?array {
	$bytes  = array_map( 'ord', str_split( $text ) );
	$length = count( $bytes );

	$version = 0;

	foreach ( diluxone_users_qr_capacity() as $v => $max ) {
		if ( $length <= $max ) {
			$version = $v;
			break;
		}
	}

	if ( 0 === $version ) {
		return null;
	}

	[ $ec_per_block, $g1_blocks, $g1_words, $g2_blocks, $g2_words ] = diluxone_users_qr_blocks()[ $version ];

	$total_data = $g1_blocks * $g1_words + $g2_blocks * $g2_words;

	// The bit stream: mode (0100), length, the data, and the padding.
	// The length field is 8 bits up to version 9 and 16 from version 10.
	$bits  = '0100';
	$bits .= str_pad( decbin( $length ), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT );

	foreach ( $bytes as $byte ) {
		$bits .= str_pad( decbin( $byte ), 8, '0', STR_PAD_LEFT );
	}

	// A terminator of up to four zeros and padding to a whole byte.
	$bits .= str_repeat( '0', min( 4, $total_data * 8 - strlen( $bits ) ) );
	$bits .= str_repeat( '0', ( 8 - strlen( $bits ) % 8 ) % 8 );

	// And after that, the two alternating padding bytes the standard prescribes.
	$padding = array( 0xEC, 0x11 );
	$i       = 0;

	$target = $total_data * 8;
	$placed = strlen( $bits );

	while ( $placed < $target ) {
		$bits   .= str_pad( decbin( $padding[ $i % 2 ] ), 8, '0', STR_PAD_LEFT );
		$placed += 8;
		++$i;
	}

	$codewords = array_map( 'bindec', str_split( $bits, 8 ) );

	// It is split into blocks, the correction of each one is worked out, and
	// then they are interleaved: first codeword 0 of every block, then 1, etc.
	$data_blocks = array();
	$ec_blocks   = array();
	$offset      = 0;

	foreach ( array( array( $g1_blocks, $g1_words ), array( $g2_blocks, $g2_words ) ) as [$blocks, $words] ) {
		for ( $b = 0; $b < $blocks; $b++ ) {
			$block         = array_slice( $codewords, $offset, $words );
			$offset       += $words;
			$data_blocks[] = $block;
			$ec_blocks[]   = diluxone_users_qr_ec( $block, $ec_per_block );
		}
	}

	$stream = array();

	$longest = max( $g1_words, $g2_words );

	for ( $i = 0; $i < $longest; $i++ ) {
		foreach ( $data_blocks as $block ) {
			if ( isset( $block[ $i ] ) ) {
				$stream[] = $block[ $i ];
			}
		}
	}

	for ( $i = 0; $i < $ec_per_block; $i++ ) {
		foreach ( $ec_blocks as $block ) {
			if ( isset( $block[ $i ] ) ) {
				$stream[] = $block[ $i ];
			}
		}
	}

	$final = '';

	foreach ( $stream as $codeword ) {
		$final .= str_pad( decbin( (int) $codeword ), 8, '0', STR_PAD_LEFT );
	}

	return diluxone_users_qr_place( (int) $version, $final );
}

/**
 * Draws the matrix: fixed patterns, data and mask.
 *
 * @return array<int, array<int, bool>>
 */
function diluxone_users_qr_place( int $version, string $bits ): array {
	$size = 17 + 4 * $version;

	$matrix   = array_fill( 0, $size, array_fill( 0, $size, false ) );
	$reserved = array_fill( 0, $size, array_fill( 0, $size, false ) );

	$set = static function ( int $r, int $c, bool $dark ) use ( &$matrix, &$reserved ): void {
		$matrix[ $r ][ $c ]   = $dark;
		$reserved[ $r ][ $c ] = true;
	};

	// The three corner squares, with their separator.
	foreach ( array( array( 0, 0 ), array( 0, $size - 7 ), array( $size - 7, 0 ) ) as [$row, $col] ) {
		for ( $r = -1; $r <= 7; $r++ ) {
			for ( $c = -1; $c <= 7; $c++ ) {
				if ( $row + $r < 0 || $row + $r >= $size || $col + $c < 0 || $col + $c >= $size ) {
					continue;
				}

				// Outside the 7×7 square it is the separator, which is always
				// white: without that, the separator corners came out black and
				// no reader found the pattern.
				$inside = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
				$borde  = 0 === $r || 6 === $r || 0 === $c || 6 === $c;
				$centre = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;

				$set( $row + $r, $col + $c, $inside && ( $borde || $centre ) );
			}
		}
	}

	// The alignment patterns, except where they clash with the corner ones.
	$centres = diluxone_users_qr_alignment()[ $version ];

	foreach ( $centres as $row ) {
		foreach ( $centres as $col ) {
			$corner = ( 6 === $row && 6 === $col )
				|| ( 6 === $row && $col === $size - 7 )
				|| ( $row === $size - 7 && 6 === $col );

			if ( $corner ) {
				continue;
			}

			for ( $r = -2; $r <= 2; $r++ ) {
				for ( $c = -2; $c <= 2; $c++ ) {
					$set( $row + $r, $col + $c, 2 === max( abs( $r ), abs( $c ) ) || ( 0 === $r && 0 === $c ) );
				}
			}
		}
	}

	// The two dotted lines.
	for ( $i = 8; $i < $size - 8; $i++ ) {
		$set( 6, $i, 0 === $i % 2 );
		$set( $i, 6, 0 === $i % 2 );
	}

	// The format information goes in two copies. Bit 0 is the least
	// significant, and each copy spreads it differently: one runs down column 8
	// and the other along row 8. Both sets of positions come from the standard
	// and cannot be derived; they are written out as they are.
	$format = DILUXONE_USERS_QR_FORMAT;

	for ( $i = 0; $i < 15; $i++ ) {
		$bit = '1' === $format[ 14 - $i ];

		// Copia vertical.
		if ( $i < 6 ) {
			$set( $i, 8, $bit );
		} elseif ( $i < 8 ) {
			$set( $i + 1, 8, $bit );
		} else {
			$set( $size - 15 + $i, 8, $bit );
		}

		// Copia horizontal.
		if ( $i < 8 ) {
			$set( 8, $size - 1 - $i, $bit );
		} elseif ( 8 === $i ) {
			$set( 8, 7, $bit );
		} else {
			$set( 8, 14 - $i, $bit );
		}
	}

	// The module that is always black. It goes after the format information
	// because it falls right on top of one of its places.
	$set( $size - 8, 8, true );

	// The version information, from version 7 onwards.
	if ( $version >= 7 ) {
		$info = diluxone_users_qr_version_info()[ $version ];

		for ( $i = 0; $i < 18; $i++ ) {
			$bit = '1' === $info[ 17 - $i ];
			$r   = intdiv( $i, 3 );
			$c   = $i % 3;

			$set( $r, $size - 11 + $c, $bit );
			$set( $size - 11 + $c, $r, $bit );
		}
	}

	// And now the data: zigzagging two columns at a time, from bottom right
	// upwards, skipping column 6, which is the dotted line.
	$index = 0;
	$total = strlen( $bits );
	$up    = true;

	for ( $col = $size - 1; $col > 0; $col -= 2 ) {
		if ( 6 === $col ) {
			--$col;
		}

		for ( $i = 0; $i < $size; $i++ ) {
			$row = $up ? $size - 1 - $i : $i;

			foreach ( array( $col, $col - 1 ) as $c ) {
				if ( $reserved[ $row ][ $c ] ) {
					continue;
				}

				$bit = $index < $total && '1' === $bits[ $index ];
				++$index;

					// Mask 0: it is inverted where (row + column) is even.
				$matrix[ $row ][ $c ] = 0 === ( $row + $c ) % 2 ? ! $bit : $bit;
			}
		}

		$up = ! $up;
	}

	return $matrix;
}

/**
 * The QR of a text, as an SVG ready to print.
 *
 * @param int $size Side in pixels.
 */
function diluxone_users_qr_svg( string $text, int $size = 220 ): string {
	$matrix = diluxone_users_qr_matrix( $text );

	if ( null === $matrix ) {
		return '';
	}

	$modules = count( $matrix );
	// Four modules of margin: the standard asks for them and without them some
	// readers do not find the code.
	$quiet = 4;
	$side  = $modules + $quiet * 2;

	$path = '';

	foreach ( $matrix as $r => $row ) {
		foreach ( $row as $c => $dark ) {
			if ( $dark ) {
				$path .= sprintf( 'M%d %dh1v1h-1z', $c + $quiet, $r + $quiet );
			}
		}
	}

	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges" role="img">'
			. '<rect width="%1$d" height="%1$d" fill="#ffffff"/><path d="%3$s" fill="#000000"/></svg>',
		$side,
		$size,
		$path
	);
}
