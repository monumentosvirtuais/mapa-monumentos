<?php
/**
 * Plugin Name: MV · Erguer monumento (Mapa Wi-Fi v2.0)
 * Description: Recebe os monumentos criados no Mapa Wi-Fi v2.0. Cria o monumento e o perfil do artista
 *              com os mesmos campos do plugin monumentos-virtuais-v3, publica na hora com a etiqueta
 *              "em checagem" e avisa a equipe por e-mail ("Monumento inscrito") com o link para checar.
 * Version:     1.0.0
 *
 * Instalação (mu-plugin, sem mexer no plugin principal):
 *   ln -s ~/domains/monumentosvirtuais.ong.br/public_html/mapa-wifi/wp/mv-erguer.php \
 *         ~/domains/monumentosvirtuais.ong.br/public_html/wp-content/mu-plugins/mv-erguer.php
 * Para desligar: apagar esse link (rm .../wp-content/mu-plugins/mv-erguer.php).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'MVE_PARA' ) ) { define( 'MVE_PARA', 'monumentosvirtuais@gmail.com' ); }   // quem recebe o aviso
if ( ! defined( 'MVE_LIMITE_HORA' ) ) { define( 'MVE_LIMITE_HORA', 3 ); }                    // envios por aparelho/IP por hora
define( 'MVE_RAIOS', array( 50, 100, 200, 300, 500, 750, 1000, 1500, 2000 ) );
define( 'MVE_MAX_FOTOS', 8 );
define( 'MVE_MAX_BYTES', 10 * 1024 * 1024 );

/* ------------------------------------------------------------------ */
/* 1. Receber o envio do mapa                                          */
/* ------------------------------------------------------------------ */
add_action( 'rest_api_init', function () {
	register_rest_route( 'mv-erguer/v1', '/enviar', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'mve_enviar',
	) );
} );

function mve_erro( $msg, $status = 400 ) {
	return new WP_REST_Response( array( 'ok' => false, 'erro' => $msg ), $status );
}

function mve_txt( $r, $k, $max ) {
	$v = sanitize_text_field( (string) $r->get_param( $k ) );
	return mb_substr( trim( $v ), 0, $max );
}
function mve_area( $r, $k, $max ) {
	$v = sanitize_textarea_field( (string) $r->get_param( $k ) );
	return mb_substr( trim( $v ), 0, $max );
}

function mve_ytid( $u ) {
	$u = trim( (string) $u );
	if ( '' === $u ) { return ''; }
	if ( preg_match( '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})~', $u, $m ) ) { return $m[1]; }
	return preg_match( '/^[A-Za-z0-9_-]{11}$/', $u ) ? $u : false;
}

/** Lista de arquivos enviados num campo (aceita campo único ou campo[]). */
function mve_arquivos( $campo ) {
	if ( empty( $_FILES[ $campo ] ) || ! is_array( $_FILES[ $campo ] ) ) { return array(); }
	$f = $_FILES[ $campo ];
	if ( ! is_array( $f['name'] ) ) { return array( $f ); }
	$o = array();
	foreach ( array_keys( $f['name'] ) as $i ) {
		$o[] = array( 'name' => $f['name'][ $i ], 'type' => $f['type'][ $i ], 'tmp_name' => $f['tmp_name'][ $i ], 'error' => $f['error'][ $i ], 'size' => $f['size'][ $i ] );
	}
	return $o;
}

function mve_imagem_ok( $f ) {
	if ( (int) $f['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file( $f['tmp_name'] ) ) { return false; }
	if ( (int) $f['size'] <= 0 || (int) $f['size'] > MVE_MAX_BYTES ) { return false; }
	$info = @getimagesize( $f['tmp_name'] );
	return $info && in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true );
}

function mve_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', $_SERVER['REMOTE_ADDR'] ) : '0';
}

function mve_enviar( WP_REST_Request $r ) {
	// robôs preenchem o campo escondido: responde "ok" e não grava nada
	if ( '' !== trim( (string) $r->get_param( 'aSite' ) ) ) { return array( 'ok' => true ); }

	$chave = 'mve_ip_' . md5( mve_ip() );
	$n     = (int) get_transient( $chave );
	if ( $n >= MVE_LIMITE_HORA ) { return mve_erro( 'Muitos envios seguidos deste aparelho. Tente de novo daqui a uma hora.', 429 ); }

	// ---- campos
	$nome   = mve_txt( $r, 'mName', 90 );
	$hist   = mve_area( $r, 'mHist', 1200 );
	$ano    = preg_replace( '/\D/', '', mve_txt( $r, 'mYear', 4 ) );
	$bairro = mve_txt( $r, 'mBairro', 60 );
	$fcred  = mve_txt( $r, 'mCred', 120 );
	$yt     = mve_ytid( $r->get_param( 'mYt' ) );
	$lat    = (float) $r->get_param( 'lat' );
	$lng    = (float) $r->get_param( 'lng' );
	$raio   = (int) $r->get_param( 'raio' );
	$anome  = mve_txt( $r, 'aNome', 80 );
	$acid   = mve_txt( $r, 'aCidade', 60 );
	$abio   = mve_area( $r, 'aBio', 400 );
	$acred  = mve_txt( $r, 'aCred', 80 );
	$email  = sanitize_email( (string) $r->get_param( 'aEmail' ) );
	$ficha  = json_decode( (string) $r->get_param( 'ficha' ), true );

	if ( '' === $nome ) { return mve_erro( 'Falta o nome do monumento.' ); }
	if ( mb_strlen( $hist ) < 20 ) { return mve_erro( 'Conte um pouco mais da história.' ); }
	if ( false === $yt ) { return mve_erro( 'O link do vídeo não é do YouTube.' ); }
	if ( '' !== $ano && ( (int) $ano < 1900 || (int) $ano > 2100 ) ) { $ano = ''; }
	if ( '' === $bairro ) { return mve_erro( 'Falta o bairro / cidade.' ); }
	if ( ! $lat || ! $lng || abs( $lat ) > 90 || abs( $lng ) > 180 ) { return mve_erro( 'Falta marcar o lugar no mapa.' ); }
	if ( ! in_array( $raio, MVE_RAIOS, true ) ) { $raio = 300; }
	if ( '' === $anome ) { return mve_erro( 'Falta o seu nome.' ); }
	if ( mb_strlen( $abio ) < 20 ) { return mve_erro( 'Escreva uma minibio.' ); }
	if ( ! is_email( $email ) ) { return mve_erro( 'Confira o e-mail.' ); }

	$linhas = array();
	if ( is_array( $ficha ) ) {
		foreach ( array_slice( $ficha, 0, 20 ) as $f ) {
			$role   = isset( $f['role'] ) ? mb_substr( trim( sanitize_text_field( $f['role'] ) ), 0, 80 ) : '';
			$people = isset( $f['people'] ) ? implode( ', ', array_map( 'sanitize_text_field', (array) $f['people'] ) ) : '';
			$people = mb_substr( trim( $people ), 0, 160 );
			$role   = str_replace( ':', ' —', $role ); // o plugin separa função e nomes pelo primeiro ":"
			if ( '' !== $role && '' !== $people ) { $linhas[] = $role . ': ' . $people; }
		}
	}
	if ( ! $linhas ) { return mve_erro( 'Coloque pelo menos uma função na ficha técnica.' ); }

	$fotos = array_slice( mve_arquivos( 'fotos' ), 0, MVE_MAX_FOTOS );
	foreach ( $fotos as $f ) { if ( ! mve_imagem_ok( $f ) ) { return mve_erro( 'Uma das fotos não pôde ser lida. Use JPG ou PNG.' ); } }
	if ( ! $yt && ! $fotos ) { return mve_erro( 'Coloque um vídeo do YouTube ou pelo menos uma foto.' ); }
	$perfil = mve_arquivos( 'aFoto' );
	if ( ! $perfil || ! mve_imagem_ok( $perfil[0] ) ) { return mve_erro( 'Falta a sua foto de perfil (JPG ou PNG).' ); }

	set_transient( $chave, $n + 1, HOUR_IN_SECONDS );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$criados = array();
	$desfazer = function () use ( &$criados ) {
		foreach ( array_reverse( $criados ) as $id ) { wp_delete_post( $id, true ); }
	};

	// ---- artista (reaproveita o perfil criado antes com o mesmo e-mail)
	$exist = get_posts( array( 'post_type' => 'mv3_artist', 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 1, 'fields' => 'ids',
		'meta_query' => array( array( 'key' => '_mve_email', 'value' => strtolower( $email ) ), array( 'key' => '_mve_comunidade', 'value' => '1' ) ) ) );
	$novo_artista = ! $exist;
	if ( $exist ) {
		$artista = (int) $exist[0];
		if ( 'publish' !== get_post_status( $artista ) ) { wp_update_post( array( 'ID' => $artista, 'post_status' => 'publish' ) ); }
	} else {
		$artista = wp_insert_post( array( 'post_type' => 'mv3_artist', 'post_status' => 'publish', 'post_title' => $anome, 'post_content' => $abio ), true );
		if ( is_wp_error( $artista ) ) { return mve_erro( 'Não foi possível criar o perfil agora. Tente de novo.', 500 ); }
		$criados[] = $artista;
		update_post_meta( $artista, '_mv3_city', $acid );
		update_post_meta( $artista, '_mv3_artist_role', 'autor' );
		update_post_meta( $artista, '_mv3_photo_credit', $acred );
		update_post_meta( $artista, '_mve_email', strtolower( $email ) ); // privado: não aparece no site
		update_post_meta( $artista, '_mve_comunidade', '1' );
		$_FILES['mve_perfil'] = $perfil[0];
		$foto = media_handle_upload( 'mve_perfil', $artista, array( 'post_title' => $anome ), array( 'test_form' => false ) );
		if ( is_wp_error( $foto ) ) { $desfazer(); return mve_erro( 'Não foi possível guardar a sua foto. Tente outra imagem.', 500 ); }
		$criados[] = $foto;
		update_post_meta( $foto, '_wp_attachment_image_alt', 'Retrato de ' . $anome );
		set_post_thumbnail( $artista, $foto );
	}

	// ---- monumento
	$mon = wp_insert_post( array( 'post_type' => 'mv3_monument', 'post_status' => 'publish', 'post_title' => $nome, 'post_content' => $hist ), true );
	if ( is_wp_error( $mon ) ) { $desfazer(); return mve_erro( 'Não foi possível criar o monumento agora. Tente de novo.', 500 ); }
	$criados[] = $mon;
	$meta = array(
		'_mv3_lat'        => $lat,
		'_mv3_lng'        => $lng,
		'_mv3_city'       => $bairro,
		'_mv3_year'       => $ano,
		'_mv3_ytid'       => $yt ? $yt : '',
		'_mv3_artist_id'  => $artista,
		'_mv3_ficha'      => implode( "\n", $linhas ),
		'_mve_raio'       => $raio,
		'_mve_checagem'   => '1',
		'_mve_comunidade' => '1',
		'_mve_fotos_credito' => $fcred,
		'_mve_enviado_em' => current_time( 'mysql' ),
	);
	foreach ( $meta as $k => $v ) { update_post_meta( $mon, $k, $v ); }

	$ids = array();
	foreach ( $fotos as $i => $f ) {
		$_FILES['mve_foto'] = $f;
		$id = media_handle_upload( 'mve_foto', $mon, array( 'post_title' => $nome . ' — foto ' . ( $i + 1 ) ), array( 'test_form' => false ) );
		if ( is_wp_error( $id ) ) { $desfazer(); return mve_erro( 'Não foi possível guardar as fotos. Tente de novo.', 500 ); }
		$criados[] = $id;
		update_post_meta( $id, '_wp_attachment_image_alt', $nome );
		$ids[] = $id;
	}
	update_post_meta( $mon, '_mve_fotos', $ids );
	if ( $ids ) { set_post_thumbnail( $mon, $ids[0] ); }

	$enviado = mve_email_inscrito( $mon, $artista, $email, $novo_artista );
	update_post_meta( $mon, '_mve_email_enviado', $enviado ? '1' : '0' );

	return array( 'ok' => true, 'id' => $mon, 'link' => get_permalink( $mon ), 'artista' => get_permalink( $artista ) );
}

/* ------------------------------------------------------------------ */
/* 2. E-mail "Monumento inscrito"                                      */
/* ------------------------------------------------------------------ */
function mve_token( $id, $acao ) {
	return substr( hash_hmac( 'sha256', $id . '|' . $acao, wp_salt( 'auth' ) ), 0, 32 );
}
function mve_link_moderar( $id, $acao ) {
	return add_query_arg( array( 'mv_moderar' => $acao, 'id' => $id, 't' => mve_token( $id, $acao ) ), home_url( '/' ) );
}
function mve_dominio() {
	$h = wp_parse_url( home_url(), PHP_URL_HOST );
	return preg_replace( '/^www\./', '', (string) $h );
}

function mve_email_inscrito( $mon, $artista, $email, $novo_artista ) {
	$m     = get_post( $mon );
	$a     = get_post( $artista );
	$link  = get_permalink( $mon );
	$lat   = get_post_meta( $mon, '_mv3_lat', true );
	$lng   = get_post_meta( $mon, '_mv3_lng', true );
	$raio  = (int) get_post_meta( $mon, '_mve_raio', true );
	$yt    = get_post_meta( $mon, '_mv3_ytid', true );
	$ano   = get_post_meta( $mon, '_mv3_year', true );
	$fotos = (array) get_post_meta( $mon, '_mve_fotos', true );
	$fcred = get_post_meta( $mon, '_mve_fotos_credito', true );
	$ficha = function_exists( 'mv3_parse_ficha' ) ? mv3_parse_ficha( get_post_meta( $mon, '_mv3_ficha', true ) ) : array();
	$mapa  = 'https://www.openstreetmap.org/?mlat=' . rawurlencode( $lat ) . '&mlon=' . rawurlencode( $lng ) . '#map=17/' . rawurlencode( $lat ) . '/' . rawurlencode( $lng );
	$raio_t = $raio >= 1000 ? str_replace( '.', ',', (string) ( $raio / 1000 ) ) . ' km' : $raio . ' m';
	$e = 'esc_html';

	$lin = function ( $rot, $val ) {
		return '<tr><td style="padding:9px 14px 9px 0;color:#7a726a;font-size:13px;vertical-align:top;white-space:nowrap">' . esc_html( $rot ) . '</td><td style="padding:9px 0;color:#141210;font-size:14px;line-height:1.5">' . $val . '</td></tr>';
	};
	$btn = function ( $txt, $url, $cheio ) {
		$s = $cheio ? 'background:#C67033;color:#ffffff;border:1px solid #C67033' : 'background:#ffffff;color:#141210;border:1px solid #c9c2b8';
		return '<a href="' . esc_url( $url ) . '" style="display:inline-block;margin:0 8px 8px 0;padding:13px 20px;border-radius:999px;font-weight:700;font-size:14px;text-decoration:none;' . $s . '">' . esc_html( $txt ) . '</a>';
	};

	$thumbs = '';
	foreach ( array_slice( $fotos, 0, 8 ) as $fid ) {
		$t = wp_get_attachment_image_url( $fid, 'thumbnail' );
		$g = wp_get_attachment_image_url( $fid, 'large' );
		if ( $t ) { $thumbs .= '<a href="' . esc_url( $g ? $g : $t ) . '"><img src="' . esc_url( $t ) . '" width="84" height="84" alt="" style="width:84px;height:84px;object-fit:cover;border-radius:8px;margin:0 6px 6px 0;border:1px solid #e3ddd4"></a>'; }
	}
	$ficha_html = '';
	foreach ( $ficha as $f ) {
		$ficha_html .= '<tr><td style="padding:6px 14px 6px 0;color:#7a726a;font-size:13px;vertical-align:top">' . $e( $f['role'] ) . '</td><td style="padding:6px 0;font-size:13px">' . $e( implode( ', ', $f['people'] ) ) . '</td></tr>';
	}
	$retrato = get_the_post_thumbnail_url( $artista, 'thumbnail' );

	$corpo  = '<div style="background:#f2ede6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif">';
	$corpo .= '<div style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e3ddd4">';
	$corpo .= '<div style="background:#0A0A0A;padding:18px 24px"><div style="color:#C67033;font-size:11px;letter-spacing:2px;text-transform:uppercase">Monumentos Virtuais · Mapa Wi-Fi v2.0</div><div style="color:#F2EDE6;font-size:22px;font-weight:700;margin-top:6px">Monumento inscrito</div></div>';
	$corpo .= '<div style="padding:22px 24px">';
	$corpo .= '<p style="margin:0 0 6px;font-size:20px;font-weight:700;color:#141210">' . $e( $m->post_title ) . '</p>';
	$corpo .= '<p style="margin:0 0 16px;color:#7a726a;font-size:14px">por ' . $e( $a->post_title ) . ' · ' . $e( get_post_meta( $mon, '_mv3_city', true ) ) . ( $ano ? ' · ' . $e( $ano ) : '' ) . '</p>';
	$corpo .= $btn( 'Abrir o monumento para checar', $link, true );
	$corpo .= '<p style="margin:2px 0 18px;font-size:12px;color:#7a726a;word-break:break-all">Link: <a href="' . esc_url( $link ) . '" style="color:#A35A27">' . $e( $link ) . '</a></p>';
	$corpo .= '<p style="margin:0 0 18px;padding:12px 14px;background:#fbf3e6;border:1px solid #f0d9b5;border-radius:10px;font-size:13px;color:#5c4330;line-height:1.5">Ele já está no ar no mapa, em Monumentos e em Artistas, com a etiqueta <b>em checagem</b>. Depois de conferir, escolha abaixo. Para falar com o artista, é só responder este e-mail.</p>';
	$corpo .= $btn( '✓ Está ok', mve_link_moderar( $mon, 'ok' ), true ) . $btn( 'Tirar do ar', mve_link_moderar( $mon, 'tirar' ), false );
	$corpo .= '<table style="width:100%;border-collapse:collapse;margin-top:14px;border-top:1px solid #eee7dd">';
	$corpo .= $lin( 'Monumento', '<a href="' . esc_url( $link ) . '" style="color:#A35A27">' . $e( $m->post_title ) . '</a>' );
	$corpo .= $lin( 'Artista', '<a href="' . esc_url( get_permalink( $artista ) ) . '" style="color:#A35A27">' . $e( $a->post_title ) . '</a>' . ( $novo_artista ? ' <span style="color:#7a726a">(perfil novo)</span>' : ' <span style="color:#7a726a">(perfil já existente)</span>' ) );
	$corpo .= $lin( 'E-mail do artista', '<a href="mailto:' . esc_attr( $email ) . '" style="color:#A35A27">' . $e( $email ) . '</a>' );
	$corpo .= $lin( 'Território', '<a href="' . esc_url( $mapa ) . '" style="color:#A35A27">' . $e( $lat . ', ' . $lng ) . '</a> · raio de ' . $e( $raio_t ) );
	$corpo .= $lin( 'Vídeo', $yt ? '<a href="' . esc_url( 'https://www.youtube.com/watch?v=' . $yt ) . '" style="color:#A35A27">youtube.com/watch?v=' . $e( $yt ) . '</a>' : '—' );
	$corpo .= $lin( 'Fotos', $fotos ? count( $fotos ) . ( $fcred ? ' · autoria: ' . $e( $fcred ) : '' ) . '<br>' . $thumbs : '—' );
	$corpo .= $lin( 'História', nl2br( $e( $m->post_content ) ) );
	$corpo .= $lin( 'Ficha técnica', '<table style="border-collapse:collapse">' . $ficha_html . '</table>' );
	$corpo .= $lin( 'Minibio', nl2br( $e( $a->post_content ) ) );
	$corpo .= $lin( 'Foto do perfil', ( $retrato ? '<img src="' . esc_url( $retrato ) . '" width="72" height="72" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover"><br>' : '' ) . 'autoria: ' . $e( get_post_meta( $artista, '_mv3_photo_credit', true ) ? get_post_meta( $artista, '_mv3_photo_credit', true ) : 'do próprio artista' ) );
	$corpo .= '</table>';
	$corpo .= '<p style="margin:18px 0 0;font-size:12px;color:#7a726a">Também dá para editar pelo painel: <a href="' . esc_url( admin_url( 'post.php?post=' . $mon . '&action=edit' ) ) . '" style="color:#A35A27">abrir no WordPress</a>.</p>';
	$corpo .= '</div></div></div>';

	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: Monumentos Virtuais <nao-responda@' . mve_dominio() . '>',
		'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>', ',' ), '', $a->post_title ) . ' <' . $email . '>',
	);
	return wp_mail( MVE_PARA, 'Monumento inscrito: ' . $m->post_title, $corpo, $headers );
}

/* ------------------------------------------------------------------ */
/* 3. Botões do e-mail: "Está ok" / "Tirar do ar" (com confirmação)    */
/* ------------------------------------------------------------------ */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['mv_moderar'] ) ) { return; }
	$acao = sanitize_key( wp_unslash( $_GET['mv_moderar'] ) );
	$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	$t    = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
	$post = get_post( $id );
	if ( ! in_array( $acao, array( 'ok', 'tirar', 'voltar' ), true ) || ! $post || 'mv3_monument' !== $post->post_type
		|| '1' !== get_post_meta( $id, '_mve_comunidade', true ) || ! hash_equals( mve_token( $id, $acao ), $t ) ) {
		mve_pagina( 'Link inválido', '<p>Este link não vale mais ou foi copiado pela metade.</p>' );
	}
	$nome  = esc_html( $post->post_title );
	$verbo = array( 'ok' => 'Marcar como conferido', 'tirar' => 'Tirar do ar', 'voltar' => 'Colocar de volta no ar' );

	// Primeiro mostra a pergunta; só age depois do clique (leitores de link do e-mail não disparam a ação).
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		$expl = array(
			'ok'     => 'A etiqueta "em checagem" sai do mapa. O monumento continua no ar.',
			'tirar'  => 'O monumento sai do mapa e de Monumentos. Se o artista não tiver outra obra no ar, o perfil também sai de Artistas. Nada é apagado.',
			'voltar' => 'O monumento (e o perfil do artista) voltam para o ar, com a etiqueta "em checagem".',
		);
		mve_pagina( $verbo[ $acao ] . '?', '<p style="font-size:18px;margin:0 0 6px"><b>' . $nome . '</b></p><p>' . esc_html( $expl[ $acao ] ) . '</p>'
			. '<form method="post"><button type="submit" style="margin-top:10px;padding:13px 22px;border-radius:999px;border:0;background:#C67033;color:#fff;font-weight:700;font-size:15px;cursor:pointer">' . esc_html( $verbo[ $acao ] ) . '</button></form>'
			. '<p style="margin-top:18px"><a href="' . esc_url( get_permalink( $id ) ) . '" style="color:#E3A46F">Ver o monumento</a></p>' );
	}

	$artista = (int) get_post_meta( $id, '_mv3_artist_id', true );
	if ( 'ok' === $acao ) {
		delete_post_meta( $id, '_mve_checagem' );
		update_post_meta( $id, '_mve_conferido_em', current_time( 'mysql' ) );
		mve_pagina( 'Conferido', '<p><b>' . $nome . '</b> está no ar, sem a etiqueta "em checagem".</p><p><a href="' . esc_url( get_permalink( $id ) ) . '" style="color:#E3A46F">Ver o monumento</a></p>' );
	}
	if ( 'tirar' === $acao ) {
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		if ( $artista && '1' === get_post_meta( $artista, '_mve_comunidade', true ) ) {
			$outras = get_posts( array( 'post_type' => 'mv3_monument', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_mv3_artist_id', 'meta_value' => $artista ) );
			if ( ! $outras ) { wp_update_post( array( 'ID' => $artista, 'post_status' => 'draft' ) ); }
		}
		mve_pagina( 'Fora do ar', '<p><b>' . $nome . '</b> saiu do mapa. Nada foi apagado: ele fica como rascunho no WordPress.</p><p><a href="' . esc_url( mve_link_moderar( $id, 'voltar' ) ) . '" style="color:#E3A46F">Desfazer: colocar de volta no ar</a></p>' );
	}
	if ( 'voltar' === $acao ) {
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		if ( $artista ) { wp_update_post( array( 'ID' => $artista, 'post_status' => 'publish' ) ); }
		update_post_meta( $id, '_mve_checagem', '1' );
		mve_pagina( 'De volta no ar', '<p><b>' . $nome . '</b> voltou para o mapa, com a etiqueta "em checagem".</p><p><a href="' . esc_url( get_permalink( $id ) ) . '" style="color:#E3A46F">Ver o monumento</a></p>' );
	}
} );

function mve_pagina( $titulo, $html ) {
	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $titulo ) . ' · Monumentos Virtuais</title></head>'
		. '<body style="margin:0;background:#0A0A0A;color:#F2EDE6;font-family:Arial,Helvetica,sans-serif"><div style="max-width:520px;margin:12vh auto;padding:0 20px">'
		. '<p style="color:#C67033;font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px">Monumentos Virtuais · checagem</p>'
		. '<h1 style="font-size:26px;margin:0 0 16px">' . esc_html( $titulo ) . '</h1><div style="color:#cfc7bd;line-height:1.6">' . $html . '</div></div></body></html>';
	exit;
}

/* ------------------------------------------------------------------ */
/* 4. O que o mapa e a página do monumento mostram                     */
/* ------------------------------------------------------------------ */
// Acrescenta às respostas da API do plugin: comunidade, em checagem, território e fotos.
add_filter( 'rest_post_dispatch', function ( $res, $server, $req ) {
	if ( ! ( $res instanceof WP_REST_Response ) || $res->is_error() ) { return $res; }
	$rota = $req->get_route();
	$data = $res->get_data();
	if ( '/mv3/v1/map' === $rota && is_array( $data ) ) {
		foreach ( $data as $i => $o ) {
			$id = is_array( $o ) && isset( $o['id'] ) ? (int) $o['id'] : 0;
			if ( $id && '1' === get_post_meta( $id, '_mve_comunidade', true ) ) {
				$data[ $i ]['comunidade'] = true;
				$data[ $i ]['checagem']   = '1' === get_post_meta( $id, '_mve_checagem', true );
				$data[ $i ]['raio']       = (int) get_post_meta( $id, '_mve_raio', true );
			}
		}
		$res->set_data( $data );
	} elseif ( 0 === strpos( $rota, '/mv3/v1/monuments/' ) && is_array( $data ) ) {
		$p = get_posts( array( 'post_type' => 'mv3_monument', 'name' => sanitize_title( $req['slug'] ), 'numberposts' => 1 ) );
		if ( $p && '1' === get_post_meta( $p[0]->ID, '_mve_comunidade', true ) ) {
			$id = $p[0]->ID;
			$data['comunidade']    = true;
			$data['checagem']      = '1' === get_post_meta( $id, '_mve_checagem', true );
			$data['raio']          = (int) get_post_meta( $id, '_mve_raio', true );
			$data['fotos']         = array_values( array_filter( array_map( function ( $f ) { return wp_get_attachment_image_url( $f, 'large' ); }, (array) get_post_meta( $id, '_mve_fotos', true ) ) ) );
			$data['fotos_credito'] = get_post_meta( $id, '_mve_fotos_credito', true );
			$res->set_data( $data );
		}
	}
	return $res;
}, 10, 3 );

// Página do monumento: aviso "inscrito pela comunidade · em checagem" e as fotos com a autoria.
add_filter( 'the_content', function ( $c ) {
	if ( ! is_singular( 'mv3_monument' ) || ! in_the_loop() || ! is_main_query() ) { return $c; }
	$id = get_the_ID();
	if ( '1' !== get_post_meta( $id, '_mve_comunidade', true ) ) { return $c; }
	$topo = '<p class="mve-selo" style="display:inline-block;margin:0 0 14px;padding:4px 10px;border-radius:999px;border:1px solid #5A4A22;color:#E3C46F;font-size:12px;letter-spacing:.04em">inscrito pela comunidade'
		. ( '1' === get_post_meta( $id, '_mve_checagem', true ) ? ' · em checagem' : '' ) . '</p>';
	$fotos = (array) get_post_meta( $id, '_mve_fotos', true );
	$g = '';
	if ( $fotos ) {
		$g .= '<div class="mve-fotos" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin:22px 0 6px">';
		foreach ( $fotos as $f ) {
			$u = wp_get_attachment_image_url( $f, 'large' );
			if ( $u ) { $g .= '<a href="' . esc_url( $u ) . '">' . wp_get_attachment_image( $f, 'medium', false, array( 'style' => 'width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;display:block', 'loading' => 'lazy' ) ) . '</a>'; }
		}
		$g .= '</div>';
		$cred = get_post_meta( $id, '_mve_fotos_credito', true );
		if ( $cred ) { $g .= '<p class="mve-fotos-cred" style="margin:0 0 10px;font-size:12px;opacity:.7">fotos: ' . esc_html( $cred ) . '</p>'; }
	}
	return $topo . $c . $g;
}, 20 );
