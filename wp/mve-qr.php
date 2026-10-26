<?php
/**
 * QR arte dos monumentos (modelo 2): o QR code desenhado com a primeira foto do monumento.
 * Gera uma vez, guarda em uploads/mv-qr/ e testa a leitura antes de usar. Se a foto não
 * render um código confiável, usa o QR desenhado (modelo 1), que lê sempre.
 * Carregado por mv-erguer.php. Biblioteca: chillerlan/php-qrcode (MIT), em lib/.
 */
defined( 'ABSPATH' ) || exit;

spl_autoload_register( function ( $c ) {
	$mapa = array(
		'chillerlan\\QRCode\\'   => __DIR__ . '/lib/php-qrcode/src/',
		'chillerlan\\Settings\\' => __DIR__ . '/lib/php-settings-container/src/',
	);
	foreach ( $mapa as $pre => $dir ) {
		if ( 0 === strpos( $c, $pre ) ) {
			$f = $dir . str_replace( '\\', '/', substr( $c, strlen( $pre ) ) ) . '.php';
			if ( is_file( $f ) ) { require $f; }
			return;
		}
	}
} );

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;

const MVE_QR_CREME   = array( 242, 237, 230 );
const MVE_QR_ESCURO  = array( 20, 18, 16 );
const MVE_QR_LARANJA = array( 198, 112, 51 );

/** Endereço que o QR abre. O link curto do acervo (/mv/q/…) entra aqui quando existir. */
function mve_qr_link( $mon ) {
	$curto = apply_filters( 'mve_qr_link', '', $mon );
	return $curto ? $curto : get_permalink( $mon );
}

/** Matriz do QR (nível de correção alto, sem margem). */
function mve_qr_matriz( $url ) {
	$o = new QROptions( array( 'eccLevel' => EccLevel::H, 'addQuietzone' => false ) );
	$q = new QRCode( $o );
	$q->addByteSegment( $url );
	return $q->getQRMatrix();
}

/** Lê um QR de uma imagem (o mesmo teste que um celular faria). */
function mve_qr_le( $blob ) {
	try {
		$o = new QROptions( array( 'readerUseImagickIfAvailable' => false ) );
		return (string) ( new QRCode( $o ) )->readFromBlob( $blob )->data;
	} catch ( Throwable $t ) {
		return '';
	}
}

function mve_qr_cor( $im, $rgb ) { return imagecolorallocate( $im, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2] ); }

/** Olhos (os três quadrados dos cantos) com a cor do site. */
function mve_qr_olhos( $im, $n, $S, $off, $estilo ) {
	$cre = mve_qr_cor( $im, MVE_QR_CREME ); $esc = mve_qr_cor( $im, MVE_QR_ESCURO ); $lar = mve_qr_cor( $im, MVE_QR_LARANJA );
	foreach ( array( array( 0, 0 ), array( 0, $n - 7 ), array( $n - 7, 0 ) ) as $p ) {
		$x = $off + $p[1] * $S; $y = $off + $p[0] * $S;
		$fora = 'foto' === $estilo ? $esc : $lar; $dentro = 'foto' === $estilo ? $lar : $esc;
		mve_qr_retangulo( $im, $x - $S, $y - $S, $x + 8 * $S - 1, $y + 8 * $S - 1, 0, $cre ); // separador claro
		mve_qr_retangulo( $im, $x, $y, $x + 7 * $S - 1, $y + 7 * $S - 1, 1.8 * $S, $fora );
		mve_qr_retangulo( $im, $x + $S, $y + $S, $x + 6 * $S - 1, $y + 6 * $S - 1, 1.2 * $S, $cre );
		mve_qr_retangulo( $im, $x + 2 * $S, $y + 2 * $S, $x + 5 * $S - 1, $y + 5 * $S - 1, 0.8 * $S, $dentro );
	}
}

/** Retângulo com cantos arredondados (GD não tem). */
function mve_qr_retangulo( $im, $x1, $y1, $x2, $y2, $r, $cor ) {
	$r = (int) min( $r, ( $x2 - $x1 ) / 2, ( $y2 - $y1 ) / 2 );
	if ( $r < 1 ) { imagefilledrectangle( $im, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $cor ); return; }
	imagefilledrectangle( $im, (int) ( $x1 + $r ), (int) $y1, (int) ( $x2 - $r ), (int) $y2, $cor );
	imagefilledrectangle( $im, (int) $x1, (int) ( $y1 + $r ), (int) $x2, (int) ( $y2 - $r ), $cor );
	foreach ( array( array( $x1 + $r, $y1 + $r ), array( $x2 - $r, $y1 + $r ), array( $x1 + $r, $y2 - $r ), array( $x2 - $r, $y2 - $r ) ) as $c ) {
		imagefilledellipse( $im, (int) $c[0], (int) $c[1], 2 * $r, 2 * $r, $cor );
	}
}

/** Recorte quadrado do centro da foto, no tamanho pedido. */
function mve_qr_foto( $arquivo, $lado ) {
	$src = @imagecreatefromstring( (string) @file_get_contents( $arquivo ) );
	if ( ! $src ) { return null; }
	$w = imagesx( $src ); $h = imagesy( $src ); $q = min( $w, $h );
	$dst = imagecreatetruecolor( $lado, $lado );
	imagecopyresampled( $dst, $src, 0, 0, (int) ( ( $w - $q ) / 2 ), (int) ( ( $h - $q ) / 2 ), $lado, $lado, $q, $q );
	imagedestroy( $src );
	imagefilter( $dst, IMG_FILTER_CONTRAST, -18 );
	return $dst;
}

/**
 * Desenha o QR. $modelo = 'foto' (modelo 2) ou 'desenho' (modelo 1). $px = lado aproximado da imagem.
 * Devolve a imagem GD ou null.
 */
function mve_qr_desenhar( $url, $modelo, $px, $foto = '' ) {
	$m  = mve_qr_matriz( $url );
	$n  = $m->getSize();
	$qz = 4;                                    // margem clara exigida pelos leitores
	$K  = 3;                                    // modelo 2: cada módulo = 3x3 pontinhos
	$S  = max( $K, (int) floor( $px / ( $n + 2 * $qz ) / $K ) * $K );
	$lado = ( $n + 2 * $qz ) * $S; $off = $qz * $S;
	$im = imagecreatetruecolor( $lado, $lado );
	imagefill( $im, 0, 0, mve_qr_cor( $im, MVE_QR_CREME ) );
	$fixo = array( QRMatrix::M_FINDER, QRMatrix::M_FINDER_DOT, QRMatrix::M_SEPARATOR, QRMatrix::M_ALIGNMENT, QRMatrix::M_TIMING, QRMatrix::M_FORMAT, QRMatrix::M_VERSION, QRMatrix::M_DARKMODULE );

	if ( 'foto' === $modelo ) {
		$g = $n * $K;
		$ph = $foto ? mve_qr_foto( $foto, $g ) : null;
		if ( ! $ph ) { imagedestroy( $im ); return null; }
		// luminância + pontilhado (Floyd–Steinberg)
		$lum = array(); $cor = array();
		for ( $y = 0; $y < $g; $y++ ) { for ( $x = 0; $x < $g; $x++ ) {
			$c = imagecolorat( $ph, $x, $y ); $r = ( $c >> 16 ) & 255; $gg = ( $c >> 8 ) & 255; $b = $c & 255;
			$cor[ $y ][ $x ] = array( $r, $gg, $b ); $lum[ $y ][ $x ] = 0.299 * $r + 0.587 * $gg + 0.114 * $b;
		} }
		imagedestroy( $ph );
		$pont = array();
		for ( $y = 0; $y < $g; $y++ ) { for ( $x = 0; $x < $g; $x++ ) {
			$v = $lum[ $y ][ $x ]; $novo = $v < 128 ? 0 : 255; $pont[ $y ][ $x ] = 0 === $novo; $e = $v - $novo;
			if ( $x + 1 < $g ) { $lum[ $y ][ $x + 1 ] += $e * 7 / 16; }
			if ( $y + 1 < $g ) {
				if ( $x > 0 ) { $lum[ $y + 1 ][ $x - 1 ] += $e * 3 / 16; }
				$lum[ $y + 1 ][ $x ] += $e * 5 / 16;
				if ( $x + 1 < $g ) { $lum[ $y + 1 ][ $x + 1 ] += $e / 16; }
			}
		} }
		$p = (int) ( $S / $K );
		for ( $r = 0; $r < $n; $r++ ) { for ( $c = 0; $c < $n; $c++ ) {
			$escuro = $m->check( $c, $r ); $todo = $m->checkTypeIn( $c, $r, $fixo );
			for ( $i = 0; $i < $K; $i++ ) { for ( $j = 0; $j < $K; $j++ ) {
				$y = $r * $K + $i; $x = $c * $K + $j;
				$bit = ( $todo || ( 1 === $i && 1 === $j ) ) ? $escuro : $pont[ $y ][ $x ];
				$rgb = $cor[ $y ][ $x ];
				$rgb = $bit ? array( $rgb[0] * .28, $rgb[1] * .28, $rgb[2] * .28 )
					: array( $rgb[0] * .25 + MVE_QR_CREME[0] * .75, $rgb[1] * .25 + MVE_QR_CREME[1] * .75, $rgb[2] * .25 + MVE_QR_CREME[2] * .75 );
				imagefilledrectangle( $im, $off + $x * $p, $off + $y * $p, $off + ( $x + 1 ) * $p - 1, $off + ( $y + 1 ) * $p - 1, mve_qr_cor( $im, $rgb ) );
			} }
		} }
	} else {
		$esc = mve_qr_cor( $im, MVE_QR_ESCURO ); $pad = max( 1, (int) round( $S * .09 ) );
		for ( $r = 0; $r < $n; $r++ ) { for ( $c = 0; $c < $n; $c++ ) {
			if ( ! $m->check( $c, $r ) || $m->checkTypeIn( $c, $r, array( QRMatrix::M_FINDER, QRMatrix::M_FINDER_DOT ) ) ) { continue; }
			mve_qr_retangulo( $im, $off + $c * $S + $pad, $off + $r * $S + $pad, $off + ( $c + 1 ) * $S - 1 - $pad, $off + ( $r + 1 ) * $S - 1 - $pad, $S * .42, $esc );
		} }
	}
	mve_qr_olhos( $im, $n, $S, $off, $modelo );
	return $im;
}

function mve_qr_blob( $im, $fmt = 'webp', $q = 82 ) {
	ob_start();
	if ( 'webp' === $fmt && function_exists( 'imagewebp' ) ) { imagewebp( $im, null, $q ); }
	elseif ( 'png' === $fmt ) { imagepng( $im, null, 9 ); }
	else { imagejpeg( $im, null, 88 ); }
	return ob_get_clean();
}
function mve_qr_reduz( $im, $lado ) {
	$d = imagecreatetruecolor( $lado, $lado );
	imagecopyresampled( $d, $im, 0, 0, 0, 0, $lado, $lado, imagesx( $im ), imagesy( $im ) );
	return $d;
}

/** Arquivo da foto usada no QR: a primeira foto do monumento (ou a imagem destacada). */
function mve_qr_foto_do_monumento( $mon ) {
	$ids = array_filter( array_map( 'intval', (array) get_post_meta( $mon, '_mve_fotos', true ) ) );
	$id  = $ids ? reset( $ids ) : (int) get_post_thumbnail_id( $mon );
	if ( ! $id ) { return array( 0, '' ); }
	$u = image_get_intermediate_size( $id, 'large' );
	$up = wp_get_upload_dir();
	$f = ( $u && ! empty( $u['path'] ) ) ? trailingslashit( $up['basedir'] ) . $u['path'] : get_attached_file( $id );
	return array( $id, $f && is_file( $f ) ? $f : (string) get_attached_file( $id ) );
}

/**
 * Gera (ou regenera) o QR do monumento. Guarda em uploads/mv-qr/ e na meta _mve_qr.
 * Devolve a meta ou null.
 */
function mve_qr_gerar( $mon, $forcar = false ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) { return null; }
	$url = mve_qr_link( $mon );
	list( $fid, $foto ) = mve_qr_foto_do_monumento( $mon );
	$atual = get_post_meta( $mon, '_mve_qr', true );
	if ( ! $forcar && is_array( $atual ) && $atual['link'] === $url && (int) $atual['foto'] === $fid && ! empty( $atual['arquivo'] ) && is_file( $atual['arquivo'] ) ) { return $atual; }

	$up  = wp_get_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'mv-qr'; wp_mkdir_p( $dir );
	$nome = $mon . '-' . substr( md5( $url . '|' . $fid . '|v1' ), 0, 8 );
	$ext  = function_exists( 'imagewebp' ) ? 'webp' : 'jpg';
	$modelo = '';
	foreach ( ( $foto ? array( 'foto', 'desenho' ) : array( 'desenho' ) ) as $tenta ) {
		$im = mve_qr_desenhar( $url, $tenta, 600, $foto );
		if ( ! $im ) { continue; }
		$web = mve_qr_blob( $im, $ext );
		// só usa se ler no tamanho da página e reduzido pela metade (celular mais longe, câmera pior)
		$pequeno = mve_qr_reduz( $im, (int) ( imagesx( $im ) / 2 ) );
		$ok = mve_qr_le( mve_qr_blob( $im, 'png' ) ) === $url && mve_qr_le( mve_qr_blob( $pequeno, 'png' ) ) === $url;
		imagedestroy( $pequeno );
		if ( ! $ok ) { imagedestroy( $im ); continue; }
		file_put_contents( "$dir/$nome.$ext", $web );
		imagedestroy( $im );
		$alta = mve_qr_desenhar( $url, $tenta, 2000, $foto );
		if ( $alta ) { file_put_contents( "$dir/$nome-impressao.png", mve_qr_blob( $alta, 'png' ) ); imagedestroy( $alta ); }
		$modelo = $tenta;
		break;
	}
	if ( ! $modelo ) { mve_log( 'QR do monumento #' . $mon . ' não passou no teste de leitura.' ); return null; }
	if ( is_array( $atual ) && ! empty( $atual['arquivo'] ) && basename( $atual['arquivo'] ) !== "$nome.$ext" ) {
		@unlink( $atual['arquivo'] ); @unlink( $atual['impressao'] ?? '' );
	}
	$meta = array(
		'link' => $url, 'foto' => $fid, 'modelo' => $modelo,
		'arquivo' => "$dir/$nome.$ext", 'url' => trailingslashit( $up['baseurl'] ) . "mv-qr/$nome.$ext",
		'impressao' => "$dir/$nome-impressao.png", 'url_impressao' => trailingslashit( $up['baseurl'] ) . "mv-qr/$nome-impressao.png",
		'gerado' => current_time( 'mysql' ),
	);
	update_post_meta( $mon, '_mve_qr', $meta );
	return $meta;
}

/** Bloco da página: QR + "escaneie para acessar" + baixar para imprimir. */
function mve_qr_html( $mon ) {
	$q = get_post_meta( $mon, '_mve_qr', true );
	if ( ! is_array( $q ) || empty( $q['url'] ) ) { return ''; }
	$nome = get_the_title( $mon );
	return '<div class="mve-qr" style="margin:30px 0 10px;text-align:center">'
		. '<p style="margin:0 0 12px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.75">Escaneie para acessar</p>'
		. '<img src="' . esc_url( $q['url'] ) . '" width="300" height="300" loading="lazy" alt="' . esc_attr( 'QR Code de ' . $nome ) . '" style="width:300px;max-width:80%;height:auto;border-radius:18px;display:inline-block">'
		. ( ! empty( $q['url_impressao'] ) ? '<p style="margin:12px 0 0;font-size:13px"><a href="' . esc_url( $q['url_impressao'] ) . '" download style="color:#E3A46F">Baixar em alta para imprimir</a></p>' : '' )
		. '</div>';
}
