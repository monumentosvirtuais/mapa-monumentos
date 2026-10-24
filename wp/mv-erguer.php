<?php
/**
 * Plugin Name: MV · Erguer monumento (Mapa Wi-Fi v2.0)
 * Description: Recebe os monumentos criados no Mapa Wi-Fi v2.0. Cria o monumento e o perfil do artista com os
 *              mesmos campos do plugin monumentos-virtuais-v3 e publica na hora. A equipe recebe o e-mail
 *              "Monumento inscrito" e pode: pedir ajuste à pessoa (ela edita pelo mesmo formulário e a equipe
 *              dá o "ok final"), tirar do ar ou editar no WordPress.
 * Version:     1.1.0
 *
 * Instalação (mu-plugin, sem mexer no plugin principal):
 *   ln -s ~/domains/monumentosvirtuais.ong.br/public_html/mapa-wifi/wp/mv-erguer.php \
 *         ~/domains/monumentosvirtuais.ong.br/public_html/wp-content/mu-plugins/mv-erguer.php
 * Para desligar: apagar esse link (rm .../wp-content/mu-plugins/mv-erguer.php).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'MVE_PARA' ) ) { define( 'MVE_PARA', 'monumentosvirtuais@gmail.com' ); }   // quem recebe os avisos
if ( ! defined( 'MVE_LIMITE_HORA' ) ) { define( 'MVE_LIMITE_HORA', 12 ); }                   // envios por rede (IP) por hora: oficinas usam o mesmo Wi-Fi
define( 'MVE_RAIOS', array( 50, 100, 200, 300, 500, 750, 1000, 1500, 2000 ) );
define( 'MVE_MAX_FOTOS', 8 );
define( 'MVE_MAX_BYTES', 10 * 1024 * 1024 );
define( 'MVE_LINK_DIAS', 30 ); // validade do link de ajuste enviado à pessoa

/* ================================================================== */
/* Utilitários                                                          */
/* ================================================================== */
function mve_erro( $msg, $status = 400 ) {
	return new WP_REST_Response( array( 'ok' => false, 'erro' => $msg ), $status );
}
function mve_txt( $r, $k, $max ) {
	return mb_substr( trim( sanitize_text_field( (string) $r->get_param( $k ) ) ), 0, $max );
}
function mve_area( $r, $k, $max ) {
	return mb_substr( trim( sanitize_textarea_field( (string) $r->get_param( $k ) ) ), 0, $max );
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
	if ( ! is_array( $f['name'] ) ) { return ( UPLOAD_ERR_NO_FILE === (int) $f['error'] ) ? array() : array( $f ); }
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
function mve_subir( $arquivo, $pai, $titulo, $alt ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$_FILES['mve_up'] = $arquivo;
	$id = media_handle_upload( 'mve_up', $pai, array( 'post_title' => $titulo ), array( 'test_form' => false ) );
	if ( ! is_wp_error( $id ) ) { update_post_meta( $id, '_wp_attachment_image_alt', $alt ); }
	return $id;
}
function mve_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', $_SERVER['REMOTE_ADDR'] ) : '0';
}
/** Limite de envios por hora. Devolve erro (resposta) ou null. */
function mve_limite( $chave = '', $max = MVE_LIMITE_HORA ) {
	$chave = $chave ? $chave : 'mve_ip_' . md5( mve_ip() );
	$n     = (int) get_transient( $chave );
	if ( $n >= $max ) { return mve_erro( 'Muitos envios seguidos deste aparelho. Tente de novo daqui a uma hora.', 429 ); }
	set_transient( $chave, $n + 1, HOUR_IN_SECONDS );
	return null;
}
function mve_raio_txt( $r ) {
	$r = (int) $r;
	return $r >= 1000 ? str_replace( '.', ',', (string) ( $r / 1000 ) ) . ' km' : $r . ' m';
}
function mve_dominio() {
	return preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}
function mve_artista_de( $mon ) { return (int) get_post_meta( $mon, '_mv3_artist_id', true ); }
function mve_email_de( $mon ) {
	$m = (string) get_post_meta( $mon, '_mve_email_envio', true );
	if ( $m ) { return $m; }
	$a = mve_artista_de( $mon );
	return $a ? (string) get_post_meta( $a, '_mve_email', true ) : '';
}

/** Lê e confere os campos do formulário. Na edição o e-mail não muda. Devolve array ou resposta de erro. */
function mve_campos( WP_REST_Request $r, $edicao = false, $sem_perfil = false ) {
	$c = array(
		'nome'   => mve_txt( $r, 'mName', 90 ),
		'hist'   => mve_area( $r, 'mHist', 1200 ),
		'ano'    => preg_replace( '/\D/', '', mve_txt( $r, 'mYear', 4 ) ),
		'bairro' => mve_txt( $r, 'mBairro', 60 ),
		'fcred'  => mve_txt( $r, 'mCred', 120 ),
		'yt'     => mve_ytid( $r->get_param( 'mYt' ) ),
		'drive'  => mb_substr( trim( (string) $r->get_param( 'mDrive' ) ), 0, 300 ),
		'lat'    => (float) $r->get_param( 'lat' ),
		'lng'    => (float) $r->get_param( 'lng' ),
		'raio'   => (int) $r->get_param( 'raio' ),
		'anome'  => mve_txt( $r, 'aNome', 80 ),
		'acid'   => mve_txt( $r, 'aCidade', 60 ),
		'abio'   => mve_area( $r, 'aBio', 400 ),
		'acred'  => mve_txt( $r, 'aCred', 80 ),
		'email'  => $edicao ? '' : strtolower( sanitize_email( (string) $r->get_param( 'aEmail' ) ) ),
		'ficha'  => '',
	);
	if ( '' === $c['nome'] ) { return mve_erro( 'Falta o nome do monumento.' ); }
	if ( mb_strlen( $c['hist'] ) < 20 ) { return mve_erro( 'Conte um pouco mais da história.' ); }
	if ( false === $c['yt'] ) { return mve_erro( 'O link do vídeo não é do YouTube.' ); }
	if ( '' !== $c['drive'] ) {
		$c['drive'] = esc_url_raw( $c['drive'], array( 'https' ) );
		if ( '' === $c['drive'] ) { return mve_erro( 'Cole o link completo do Drive, começando com https://' ); }
	}
	if ( '' !== $c['ano'] && ( (int) $c['ano'] < 1900 || (int) $c['ano'] > 2100 ) ) { $c['ano'] = ''; }
	if ( '' === $c['bairro'] ) { return mve_erro( 'Falta o bairro / cidade.' ); }
	if ( ! $c['lat'] || ! $c['lng'] || abs( $c['lat'] ) > 90 || abs( $c['lng'] ) > 180 ) { return mve_erro( 'Falta marcar o lugar no mapa.' ); }
	if ( ! in_array( $c['raio'], MVE_RAIOS, true ) ) { $c['raio'] = 300; }
	if ( ! $sem_perfil && '' === $c['anome'] ) { return mve_erro( 'Falta o seu nome.' ); }
	if ( ! $sem_perfil && mb_strlen( $c['abio'] ) < 20 ) { return mve_erro( 'Escreva uma minibio.' ); }
	if ( ! $edicao && ! is_email( $c['email'] ) ) { return mve_erro( 'Confira o e-mail.' ); }
	$linhas = array();
	$ficha  = json_decode( (string) $r->get_param( 'ficha' ), true );
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
	$c['ficha'] = implode( "\n", $linhas );
	return $c;
}

/** Valores atuais (no ar) de um monumento e do artista, no mesmo formato de mve_campos. */
function mve_atual( $mon ) {
	$m = get_post( $mon );
	$a = mve_artista_de( $mon );
	return array(
		'nome' => $m->post_title, 'hist' => $m->post_content, 'ano' => (string) get_post_meta( $mon, '_mv3_year', true ),
		'bairro' => (string) get_post_meta( $mon, '_mv3_city', true ), 'fcred' => (string) get_post_meta( $mon, '_mve_fotos_credito', true ),
		'yt' => (string) get_post_meta( $mon, '_mv3_ytid', true ), 'drive' => (string) get_post_meta( $mon, '_mve_drive', true ), 'lat' => (float) get_post_meta( $mon, '_mv3_lat', true ), 'lng' => (float) get_post_meta( $mon, '_mv3_lng', true ),
		'raio' => (int) get_post_meta( $mon, '_mve_raio', true ), 'ficha' => (string) get_post_meta( $mon, '_mv3_ficha', true ),
		'anome' => $a ? get_the_title( $a ) : '', 'acid' => $a ? (string) get_post_meta( $a, '_mv3_city', true ) : '',
		'abio' => $a ? (string) get_post_field( 'post_content', $a ) : '', 'acred' => $a ? (string) get_post_meta( $a, '_mv3_photo_credit', true ) : '',
		'fotos' => array_map( 'intval', (array) get_post_meta( $mon, '_mve_fotos', true ) ),
		'retrato' => $a ? (int) get_post_thumbnail_id( $a ) : 0,
	);
}

/* ================================================================== */
/* 1. Receber um monumento novo: primeiro GUARDA, depois publica        */
/* ================================================================== */
// Tudo o que chega fica guardado em "Inscrições do mapa" (WordPress) antes de qualquer outra coisa.
// Se a publicação falhar, nada se perde: a equipe recebe um e-mail com "Restaurar e publicar".
define( 'MVE_CAMPOS', array( 'mYt', 'mDrive', 'mName', 'mYear', 'mBairro', 'mHist', 'mCred', 'aNome', 'aCidade', 'aBio', 'aEmail', 'aCred', 'lat', 'lng', 'raio', 'ficha', 'artistaId' ) );

add_action( 'rest_api_init', function () {
	register_rest_route( 'mv-erguer/v1', '/enviar', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'mve_rota_enviar' ) );
	register_rest_route( 'mv-erguer/v1', '/editar', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'mve_editar' ) );
	register_rest_route( 'mv-erguer/v1', '/ajustar', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'mve_seguro_ajustar' ) );
	register_rest_route( 'mv-erguer/v1', '/artistas', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'mve_lista_artistas' ) );
} );

add_action( 'init', function () {
	register_post_type( 'mve_inscricao', array(
		'labels'              => array( 'name' => 'Inscrições do mapa', 'singular_name' => 'Inscrição do mapa', 'menu_name' => 'Inscrições do mapa', 'edit_item' => 'Inscrição do mapa' ),
		'public'              => false, 'show_ui' => true, 'show_in_menu' => true, 'menu_icon' => 'dashicons-archive', 'menu_position' => 26,
		'supports'            => array( 'title' ), 'capability_type' => 'post', 'map_meta_cap' => true,
		'exclude_from_search' => true, 'publicly_queryable' => false, 'show_in_rest' => false, 'rewrite' => false, 'query_var' => false,
	) );
} );

/** Perfis de artista publicados (o que a página Artistas já mostra): para a pessoa escolher o próprio nome. */
function mve_lista_artistas() {
	$o = array();
	foreach ( get_posts( array( 'post_type' => 'mv3_artist', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $a ) {
		$o[] = array( 'id' => $a->ID, 'nome' => $a->post_title, 'cidade' => (string) get_post_meta( $a->ID, '_mv3_city', true ), 'foto' => (string) get_the_post_thumbnail_url( $a->ID, 'thumbnail' ) );
	}
	return $o;
}

/** Registro de erros: wp-content/uploads/mv-erguer-log/erros.log (pasta protegida). */
function mve_log( $msg ) {
	$d   = wp_upload_dir();
	$dir = trailingslashit( $d['basedir'] ) . 'mv-erguer-log';
	if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); @file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); @file_put_contents( $dir . '/index.html', '' ); }
	@file_put_contents( $dir . '/erros.log', gmdate( 'Y-m-d H:i:s' ) . ' UTC  ' . $msg . "\n", FILE_APPEND );
}
/** Nunca devolve página de erro: qualquer falha vira uma mensagem em JSON e fica registrada. */
function mve_seguro( $fn, WP_REST_Request $r ) {
	try {
		$res = call_user_func( $fn, $r );
		if ( $res instanceof WP_REST_Response && $res->get_status() >= 400 ) {
			$d = $res->get_data();
			mve_log( $fn . ' recusou (' . $res->get_status() . '): ' . ( isset( $d['erro'] ) ? $d['erro'] : '' ) . ' · ip ' . mve_ip() );
		}
		return $res;
	} catch ( Throwable $t ) {
		mve_log( $fn . ' FALHOU: ' . $t->getMessage() . ' em ' . basename( $t->getFile() ) . ':' . $t->getLine() );
		return mve_erro( 'Erro no servidor (' . $t->getMessage() . '). Tente de novo em instantes.', 500 );
	}
}
function mve_seguro_ajustar( WP_REST_Request $r ) { return mve_seguro( 'mve_ajustar', $r ); }

/* ---------- o registro (cofre) ---------- */
function mve_pasta_inscricoes() {
	$d   = wp_upload_dir();
	$dir = trailingslashit( $d['basedir'] ) . 'mv-erguer-inscricoes';
	if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
	if ( ! file_exists( $dir . '/.htaccess' ) ) { @file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); @file_put_contents( $dir . '/index.html', '' ); }
	return $dir;
}
function mve_uuid_ok( $u ) {
	$u = preg_replace( '/[^a-zA-Z0-9-]/', '', (string) $u );
	return ( strlen( $u ) >= 8 && strlen( $u ) <= 64 ) ? $u : '';
}
function mve_registro_por_uuid( $uuid ) {
	$p = get_posts( array( 'post_type' => 'mve_inscricao', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_mve_uuid', 'meta_value' => $uuid ) );
	return $p ? (int) $p[0] : 0;
}
function mve_estado( $rid, $estado, $extra = array() ) {
	update_post_meta( $rid, '_mve_estado', $estado );
	foreach ( $extra as $k => $v ) { update_post_meta( $rid, $k, $v ); }
}
function mve_ext_imagem( $path ) {
	$i = @getimagesize( $path );
	if ( ! $i ) { return ''; }
	$m = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
	return isset( $m[ $i['mime'] ] ) ? $m[ $i['mime'] ] : '';
}
/** Guarda os arquivos que vieram nesta requisição (fotos[], foto, aFoto) na pasta protegida da inscrição. */
function mve_registrar_arquivos( $rid, $uuid ) {
	$pasta = trailingslashit( mve_pasta_inscricoes() ) . $uuid;
	wp_mkdir_p( $pasta );
	$arqs  = get_post_meta( $rid, '_mve_arquivos', true );
	$arqs  = is_array( $arqs ) ? $arqs : array( 'fotos' => array(), 'perfil' => '' );
	foreach ( array_merge( mve_arquivos( 'fotos' ), mve_arquivos( 'foto' ) ) as $f ) {
		if ( count( $arqs['fotos'] ) >= MVE_MAX_FOTOS || ! mve_imagem_ok( $f ) ) { continue; }
		$ext = mve_ext_imagem( $f['tmp_name'] );
		$n   = 'foto-' . ( count( $arqs['fotos'] ) + 1 ) . '-' . wp_generate_password( 6, false, false ) . '.' . $ext;
		if ( $ext && @move_uploaded_file( $f['tmp_name'], $pasta . '/' . $n ) ) { $arqs['fotos'][] = $n; }
	}
	foreach ( mve_arquivos( 'aFoto' ) as $f ) {
		if ( ! mve_imagem_ok( $f ) ) { continue; }
		$ext = mve_ext_imagem( $f['tmp_name'] );
		if ( $ext && @move_uploaded_file( $f['tmp_name'], $pasta . '/perfil.' . $ext ) ) { $arqs['perfil'] = 'perfil.' . $ext; }
	}
	update_post_meta( $rid, '_mve_arquivos', $arqs );
	return $arqs;
}
/** Guarda (ou completa) a inscrição: texto + arquivos. Devolve o ID do registro. */
function mve_registrar( WP_REST_Request $r, $uuid, $canal ) {
	$rid   = mve_registro_por_uuid( $uuid );
	$dados = array();
	foreach ( MVE_CAMPOS as $k ) {
		$v = $r->get_param( $k );
		if ( is_scalar( $v ) && '' !== (string) $v ) { $dados[ $k ] = mb_substr( (string) $v, 0, 6000 ); }
	}
	$nome = isset( $dados['mName'] ) ? sanitize_text_field( $dados['mName'] ) : '';
	if ( ! $rid ) {
		$rid = wp_insert_post( array( 'post_type' => 'mve_inscricao', 'post_status' => 'private', 'post_title' => ( '' !== $nome ? $nome : 'Inscrição sem nome' ) ), true );
		if ( is_wp_error( $rid ) ) { throw new Exception( 'não foi possível criar o registro: ' . $rid->get_error_message() ); }
		update_post_meta( $rid, '_mve_uuid', $uuid );
		update_post_meta( $rid, '_mve_recebida_em', current_time( 'mysql' ) );
		update_post_meta( $rid, '_mve_ip', mve_ip() );
		mve_estado( $rid, 'recebida' );
	}
	if ( $dados ) {
		$antes = get_post_meta( $rid, '_mve_dados', true );
		update_post_meta( $rid, '_mve_dados', wp_slash( array_merge( is_array( $antes ) ? $antes : array(), $dados ) ) );
		if ( '' !== $nome ) { wp_update_post( array( 'ID' => $rid, 'post_title' => $nome ) ); }
	}
	$canais = get_post_meta( $rid, '_mve_canais', true );
	$canais = is_array( $canais ) ? $canais : array();
	$canais[] = gmdate( 'Y-m-d H:i:s' ) . ' UTC · ' . $canal;
	update_post_meta( $rid, '_mve_canais', array_slice( $canais, -20 ) );
	mve_registrar_arquivos( $rid, $uuid );
	return $rid;
}
function mve_req_de_registro( $rid ) {
	$r = new WP_REST_Request( 'POST', '/mv-erguer/v1/enviar' );
	$d = get_post_meta( $rid, '_mve_dados', true );
	$r->set_body_params( is_array( $d ) ? $d : array() );
	return $r;
}
function mve_arquivos_de_registro( $rid ) {
	$uuid  = (string) get_post_meta( $rid, '_mve_uuid', true );
	$pasta = trailingslashit( mve_pasta_inscricoes() ) . $uuid . '/';
	$arqs  = get_post_meta( $rid, '_mve_arquivos', true );
	$o     = array( 'fotos' => array(), 'perfil' => '' );
	if ( is_array( $arqs ) ) {
		foreach ( (array) $arqs['fotos'] as $n ) { if ( is_file( $pasta . $n ) ) { $o['fotos'][] = $pasta . $n; } }
		if ( ! empty( $arqs['perfil'] ) && is_file( $pasta . $arqs['perfil'] ) ) { $o['perfil'] = $pasta . $arqs['perfil']; }
	}
	return $o;
}
function mve_subir_caminho( $path, $pai, $titulo, $alt ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = wp_tempnam( basename( $path ) );
	if ( ! $tmp || ! @copy( $path, $tmp ) ) { return new WP_Error( 'copia', 'não foi possível ler a foto guardada' ); }
	$id = media_handle_sideload( array( 'name' => sanitize_file_name( basename( $path ) ), 'tmp_name' => $tmp ), $pai, null, array( 'post_title' => $titulo ) );
	if ( is_wp_error( $id ) ) { @unlink( $tmp ); return $id; }
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return $id;
}

/* ---------- canal principal ---------- */
function mve_rota_enviar( WP_REST_Request $r ) {
	$res = mve_receber( $r, 'principal' );
	if ( $res instanceof WP_REST_Response && $res->get_status() >= 400 ) {
		$d = $res->get_data();
		mve_log( 'envio recusado (' . $res->get_status() . '): ' . ( isset( $d['erro'] ) ? $d['erro'] : '' ) . ' · ip ' . mve_ip() );
	}
	return $res;
}

/** Recebe uma inscrição completa (texto + arquivos) por qualquer canal: guarda, depois tenta publicar. */
function mve_receber( WP_REST_Request $r, $canal ) {
	// robôs preenchem o campo escondido: responde "ok" e não grava nada
	if ( '' !== trim( (string) $r->get_param( 'aSite' ) ) ) { return array( 'ok' => true ); }
	$uuid = mve_uuid_ok( $r->get_param( 'envio_id' ) );
	if ( ! $uuid ) { $uuid = wp_generate_uuid4(); }

	// mesma inscrição chegando de novo (nova tentativa): nunca duplica
	$rid = mve_registro_por_uuid( $uuid );
	if ( $rid ) {
		$e = get_post_meta( $rid, '_mve_estado', true );
		if ( 'publicada' === $e ) { $res = get_post_meta( $rid, '_mve_resultado', true ); return is_array( $res ) ? $res : array( 'ok' => true ); }
		if ( in_array( $e, array( 'guardada', 'falhou', 'processando' ), true ) ) { return array( 'ok' => true, 'guardada' => true ); }
	}

	// 1) guarda antes de tudo
	try {
		$rid = mve_registrar( $r, $uuid, $canal );
	} catch ( Throwable $t ) {
		mve_log( 'REGISTRO FALHOU (' . $canal . '): ' . $t->getMessage() . ' em ' . basename( $t->getFile() ) . ':' . $t->getLine() );
		return mve_erro( 'Não foi possível guardar a inscrição agora (' . $t->getMessage() . ').', 500 );
	}

	// 2) limite de envios por hora (proteção contra robôs)
	$lim = mve_limite();
	if ( $lim ) { mve_estado( $rid, 'limitada', array( '_mve_motivo' => 'muitos envios seguidos do mesmo endereço' ) ); return $lim; }

	// 3) publica
	mve_estado( $rid, 'processando' );
	try {
		$res = mve_processar( $rid, true );
	} catch ( Throwable $t ) {
		$msg = $t->getMessage() . ' (' . basename( $t->getFile() ) . ':' . $t->getLine() . ')';
		mve_log( 'PUBLICAÇÃO FALHOU, inscrição guardada #' . $rid . ' (' . $canal . '): ' . $msg );
		mve_estado( $rid, 'falhou', array( '_mve_motivo' => 'A publicação automática falhou: ' . $msg ) );
		mve_email_guardada( $rid );
		return array( 'ok' => true, 'guardada' => true );
	}
	if ( $res instanceof WP_REST_Response ) { // dados incompletos: a pessoa corrige e envia de novo
		$d = $res->get_data();
		mve_estado( $rid, 'invalida', array( '_mve_motivo' => isset( $d['erro'] ) ? $d['erro'] : '' ) );
		return $res;
	}
	return $res;
}

/** Publica uma inscrição guardada. Devolve o resultado, ou uma resposta de erro se faltar dado. Falhas internas viram exceção. */
function mve_processar( $rid, $avisar_equipe = true ) {
	$r      = mve_req_de_registro( $rid );
	$a      = mve_arquivos_de_registro( $rid );
	$art_id = (int) $r->get_param( 'artistaId' ); // "Já tenho perfil": o perfil escolhido na lista
	$c      = mve_campos( $r, false, $art_id > 0 );
	if ( $c instanceof WP_REST_Response ) { return $c; }
	if ( $art_id ) {
		$ap = get_post( $art_id );
		if ( ! $ap || 'mv3_artist' !== $ap->post_type || 'publish' !== $ap->post_status ) { return mve_erro( 'Não achamos esse perfil de artista. Escolha o seu nome de novo na lista.' ); }
	}
	$fotos = array_slice( $a['fotos'], 0, MVE_MAX_FOTOS );
	if ( ! $c['yt'] && ! $c['drive'] && ! $fotos ) { return mve_erro( 'Coloque um vídeo (YouTube ou Google Drive) ou pelo menos uma foto.' ); }
	if ( ! $art_id && ! $a['perfil'] ) { return mve_erro( 'Falta a sua foto de perfil (JPG ou PNG).' ); }

	$criados  = array();
	$desfazer = function () use ( &$criados ) { foreach ( array_reverse( $criados ) as $id ) { wp_delete_post( $id, true ); } };
	try {
		if ( $art_id ) {
			// perfil escolhido na lista: publica direto se o e-mail confere com o guardado nesse perfil; senão a equipe confirma
			$artista  = $art_id;
			$guardado = strtolower( (string) get_post_meta( $artista, '_mve_email', true ) );
			$modo     = ( $guardado && hash_equals( $guardado, $c['email'] ) ) ? 'existente' : 'confirmar';
		} else {
			// reaproveita o perfil criado antes com o mesmo e-mail
			$exist = get_posts( array( 'post_type' => 'mv3_artist', 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 1, 'fields' => 'ids',
				'meta_query' => array( array( 'key' => '_mve_email', 'value' => $c['email'] ), array( 'key' => '_mve_comunidade', 'value' => '1' ) ) ) );
			if ( $exist ) {
				$artista = (int) $exist[0];
				if ( 'publish' !== get_post_status( $artista ) ) { wp_update_post( array( 'ID' => $artista, 'post_status' => 'publish' ) ); }
				$modo = 'mesmo_email';
			} else {
				$artista = wp_insert_post( array( 'post_type' => 'mv3_artist', 'post_status' => 'publish', 'post_title' => $c['anome'], 'post_content' => $c['abio'] ), true );
				if ( is_wp_error( $artista ) ) { throw new Exception( 'perfil: ' . $artista->get_error_message() ); }
				$criados[] = $artista;
				update_post_meta( $artista, '_mv3_city', $c['acid'] );
				update_post_meta( $artista, '_mv3_artist_role', 'autor' );
				update_post_meta( $artista, '_mv3_photo_credit', $c['acred'] );
				update_post_meta( $artista, '_mve_email', $c['email'] ); // privado: não aparece no site
				update_post_meta( $artista, '_mve_comunidade', '1' );
				$foto = mve_subir_caminho( $a['perfil'], $artista, $c['anome'], 'Retrato de ' . $c['anome'] );
				if ( is_wp_error( $foto ) ) { throw new Exception( 'foto de perfil: ' . $foto->get_error_message() ); }
				$criados[] = $foto;
				set_post_thumbnail( $artista, $foto );
				$modo = 'novo';
			}
		}
		// monumento (aguarda a equipe só quando o perfil escolhido não pôde ser conferido pelo e-mail)
		$mon = wp_insert_post( array( 'post_type' => 'mv3_monument', 'post_status' => ( 'confirmar' === $modo ? 'pending' : 'publish' ), 'post_title' => $c['nome'], 'post_content' => $c['hist'] ), true );
		if ( is_wp_error( $mon ) ) { throw new Exception( 'monumento: ' . $mon->get_error_message() ); }
		$criados[] = $mon;
		mve_gravar_monumento( $mon, $c );
		update_post_meta( $mon, '_mv3_artist_id', $artista );
		update_post_meta( $mon, '_mve_comunidade', '1' );
		update_post_meta( $mon, '_mve_enviado_em', current_time( 'mysql' ) );
		update_post_meta( $mon, '_mve_email_envio', $c['email'] ); // privado: contato para ajustes
		update_post_meta( $mon, '_mve_inscricao', $rid );
		if ( $art_id ) { update_post_meta( $mon, '_mve_perfil_existente', '1' ); }
		if ( 'confirmar' === $modo ) { update_post_meta( $mon, '_mve_aguarda_vinculo', '1' ); }
		$ids = array();
		foreach ( $fotos as $i => $f ) {
			$id = mve_subir_caminho( $f, $mon, $c['nome'] . ' — foto ' . ( $i + 1 ), $c['nome'] );
			if ( is_wp_error( $id ) ) { throw new Exception( 'foto ' . ( $i + 1 ) . ': ' . $id->get_error_message() ); }
			$criados[] = $id;
			$ids[]     = $id;
		}
		mve_gravar_fotos( $mon, $ids );
	} catch ( Throwable $t ) {
		$desfazer(); // nada pela metade no site; a inscrição continua guardada
		throw $t;
	}
	$res = array( 'ok' => true, 'id' => $mon, 'link' => get_permalink( $mon ), 'artista' => get_permalink( $artista ), 'aguarda' => ( 'confirmar' === $modo ) );
	mve_estado( $rid, 'publicada', array( '_mve_monumento' => $mon, '_mve_resultado' => $res, '_mve_motivo' => '' ) );
	if ( $avisar_equipe ) {
		// o aviso por e-mail nunca desfaz uma publicação: se ele falhar, fica registrado e o monumento continua no ar
		try {
			$enviado = mve_email_inscrito( $mon, $modo );
		} catch ( Throwable $t ) {
			$enviado = false;
			mve_log( 'E-MAIL DO MONUMENTO #' . $mon . ' FALHOU (monumento publicado normalmente): ' . $t->getMessage() . ' em ' . basename( $t->getFile() ) . ':' . $t->getLine() );
		}
		update_post_meta( $mon, '_mve_email_enviado', $enviado ? '1' : '0' );
	}
	return $res;
}

/* ---------- plano B: outro caminho no site (admin-ajax), caso o principal seja bloqueado ---------- */
add_action( 'wp_ajax_nopriv_mve_guardar', 'mve_ajax_guardar' );
add_action( 'wp_ajax_mve_guardar', 'mve_ajax_guardar' );
function mve_ajax_guardar() {
	$r = new WP_REST_Request( 'POST', '/mv-erguer/v1/enviar' );
	$r->set_body_params( wp_unslash( $_POST ) );
	$modo = isset( $_POST['modo'] ) ? sanitize_key( wp_unslash( $_POST['modo'] ) ) : 'completo';
	try {
		if ( 'completo' === $modo ) {
			$res = mve_receber( $r, 'plano B (completo)' );
		} else {
			if ( '' !== trim( (string) $r->get_param( 'aSite' ) ) ) { wp_send_json( array( 'ok' => true ) ); }
			$uuid = mve_uuid_ok( $r->get_param( 'envio_id' ) );
			if ( ! $uuid ) { wp_send_json( array( 'ok' => false, 'erro' => 'Inscrição sem identificação.' ), 400 ); }
			$lim = mve_limite( 'mve_b_' . md5( mve_ip() ), 40 );
			if ( $lim ) { wp_send_json( $lim->get_data(), 429 ); }
			$rid = mve_registro_por_uuid( $uuid );
			if ( 'foto' === $modo ) {
				if ( ! $rid ) { wp_send_json( array( 'ok' => false, 'erro' => 'Inscrição não encontrada.' ), 404 ); }
				$arqs = mve_registrar_arquivos( $rid, $uuid );
				wp_send_json( array( 'ok' => true, 'fotos' => count( $arqs['fotos'] ), 'perfil' => '' !== $arqs['perfil'] ) );
			}
			// modo "texto": guarda o texto agora; as fotos chegam uma a uma em seguida
			$rid = mve_registrar( $r, $uuid, 'plano B (texto, fotos em seguida)' );
			if ( 'publicada' !== get_post_meta( $rid, '_mve_estado', true ) ) {
				mve_estado( $rid, 'guardada', array( '_mve_motivo' => 'Chegou pelo plano B: o envio normal não passou, então o texto chegou primeiro e as fotos foram enviadas uma a uma.' ) );
				if ( ! get_post_meta( $rid, '_mve_avisada', true ) ) { wp_schedule_single_event( time() + 120, 'mve_avisar_guardada', array( $rid ) ); }
			}
			$res = array( 'ok' => true, 'guardada' => true );
		}
	} catch ( Throwable $t ) {
		mve_log( 'PLANO B FALHOU (' . $modo . '): ' . $t->getMessage() . ' em ' . basename( $t->getFile() ) . ':' . $t->getLine() );
		wp_send_json( array( 'ok' => false, 'erro' => 'Não foi possível guardar agora.' ), 500 );
	}
	if ( $res instanceof WP_REST_Response ) { wp_send_json( $res->get_data(), $res->get_status() ); }
	wp_send_json( $res );
}
// o aviso do plano B sai 2 minutos depois, para já contar as fotos que chegaram uma a uma
add_action( 'mve_avisar_guardada', function ( $rid ) { if ( ! get_post_meta( $rid, '_mve_avisada', true ) && 'publicada' !== get_post_meta( $rid, '_mve_estado', true ) ) { mve_email_guardada( $rid ); } } );

/* ---------- e-mail "Inscrição guardada" ---------- */
function mve_email_guardada( $rid ) {
	$d     = get_post_meta( $rid, '_mve_dados', true );
	$d     = is_array( $d ) ? $d : array();
	$a     = mve_arquivos_de_registro( $rid );
	$g     = function ( $k ) use ( $d ) { return isset( $d[ $k ] ) ? (string) $d[ $k ] : ''; };
	$e     = 'esc_html';
	$nome  = $g( 'mName' ) ? $g( 'mName' ) : 'Inscrição sem nome';
	$email = sanitize_email( $g( 'aEmail' ) );
	$art   = (int) $g( 'artistaId' ) ? 'perfil já existente: ' . get_the_title( (int) $g( 'artistaId' ) ) : $g( 'aNome' ) . ' (perfil novo)';
	$ficha = '';
	$fj    = json_decode( $g( 'ficha' ), true );
	if ( is_array( $fj ) ) { foreach ( $fj as $f ) { if ( ! empty( $f['role'] ) ) { $ficha .= $e( $f['role'] ) . ': ' . $e( mve_pessoas( $f['people'] ?? '' ) ) . '<br>'; } } }
	$h  = mve_caixa( '<b>Nada se perdeu.</b> Esta inscrição chegou e está guardada no site, mas ainda <b>não foi publicada</b>.<br><span style="font-size:13px">Motivo: ' . $e( (string) get_post_meta( $rid, '_mve_motivo', true ) ) . '</span>' );
	$h .= mve_btn( 'Restaurar e publicar', mve_link_moderar( $rid, 'restaurar' ) ) . mve_btn( 'Ver no WordPress', admin_url( 'post.php?post=' . $rid . '&action=edit' ), false );
	$h .= '<table style="width:100%;border-collapse:collapse;margin-top:14px;border-top:1px solid #eee7dd">';
	$h .= mve_lin( 'Monumento', $e( $nome ) . ( $g( 'mYear' ) ? ' · ' . $e( $g( 'mYear' ) ) : '' ) );
	$h .= mve_lin( 'Artista', $e( $art ) );
	$h .= mve_lin( 'E-mail', $email ? '<a href="mailto:' . esc_attr( $email ) . '" style="color:#A35A27">' . $e( $email ) . '</a>' : '—' );
	$h .= mve_lin( 'Bairro / cidade', $e( $g( 'mBairro' ) ) );
	$h .= mve_lin( 'Território', $e( $g( 'lat' ) . ', ' . $g( 'lng' ) ) . ( $g( 'raio' ) ? ' · raio de ' . $e( mve_raio_txt( $g( 'raio' ) ) ) : '' ) );
	$h .= mve_lin( 'Vídeo', $g( 'mYt' ) ? $e( $g( 'mYt' ) ) : '—' );
	if ( $g( 'mDrive' ) ) { $h .= mve_lin( 'Vídeo no Drive', $e( $g( 'mDrive' ) ) ); }
	$h .= mve_lin( 'Fotos guardadas', count( $a['fotos'] ) . ( $a['perfil'] ? ' + foto de perfil' : '' ) . ( $g( 'mCred' ) ? ' · autoria: ' . $e( $g( 'mCred' ) ) : '' ) );
	$h .= mve_lin( 'História', nl2br( $e( $g( 'mHist' ) ) ) );
	$h .= mve_lin( 'Ficha técnica', $ficha ? $ficha : '—' );
	if ( ! (int) $g( 'artistaId' ) ) { $h .= mve_lin( 'Minibio', nl2br( $e( $g( 'aBio' ) ) ) ); }
	$h .= '</table>';
	update_post_meta( $rid, '_mve_avisada', current_time( 'mysql' ) );
	return mve_mandar( MVE_PARA, 'Inscrição guardada (não publicada): ' . $nome, mve_email_html( 'Inscrição guardada', $nome, $h ), $email ? $email : '' );
}

/* ---------- painel: o que tem em cada inscrição guardada ---------- */
add_filter( 'manage_mve_inscricao_posts_columns', function ( $c ) {
	return array( 'cb' => $c['cb'], 'title' => 'Monumento', 'mve_estado' => 'Situação', 'mve_email' => 'E-mail', 'mve_fotos' => 'Fotos', 'date' => 'Recebida' );
} );
add_action( 'manage_mve_inscricao_posts_custom_column', function ( $col, $id ) {
	$d = get_post_meta( $id, '_mve_dados', true );
	if ( 'mve_estado' === $col ) {
		$est = (string) get_post_meta( $id, '_mve_estado', true );
		$rot = array( 'publicada' => 'publicada', 'guardada' => 'guardada · restaurar', 'falhou' => 'falhou · restaurar', 'invalida' => 'incompleta', 'processando' => 'em andamento', 'recebida' => 'recebida', 'limitada' => 'barrada (limite)' );
		echo esc_html( isset( $rot[ $est ] ) ? $rot[ $est ] : $est );
		$mon = (int) get_post_meta( $id, '_mve_monumento', true );
		if ( $mon ) { echo ' · <a href="' . esc_url( get_edit_post_link( $mon ) ) . '">ver monumento</a>'; }
		elseif ( in_array( $est, array( 'guardada', 'falhou', 'processando' ), true ) ) { echo ' · <a href="' . esc_url( mve_link_moderar( $id, 'restaurar' ) ) . '">restaurar e publicar</a>'; }
	}
	if ( 'mve_email' === $col ) { echo esc_html( is_array( $d ) && isset( $d['aEmail'] ) ? $d['aEmail'] : '' ); }
	if ( 'mve_fotos' === $col ) { $a = mve_arquivos_de_registro( $id ); echo (int) count( $a['fotos'] ); }
}, 10, 2 );
add_action( 'add_meta_boxes_mve_inscricao', function () {
	add_meta_box( 'mve_dados', 'O que a pessoa enviou', function ( $p ) {
		$d   = get_post_meta( $p->ID, '_mve_dados', true );
		$est = (string) get_post_meta( $p->ID, '_mve_estado', true );
		echo '<p><b>Situação:</b> ' . esc_html( $est ) . ' · ' . esc_html( (string) get_post_meta( $p->ID, '_mve_motivo', true ) ) . '</p>';
		if ( 'publicada' !== $est ) { echo '<p><a class="button button-primary" href="' . esc_url( mve_link_moderar( $p->ID, 'restaurar' ) ) . '">Restaurar e publicar</a></p>'; }
		echo '<table class="widefat striped"><tbody>';
		foreach ( (array) $d as $k => $v ) { echo '<tr><th style="width:140px">' . esc_html( $k ) . '</th><td>' . nl2br( esc_html( (string) $v ) ) . '</td></tr>'; }
		$a = mve_arquivos_de_registro( $p->ID );
		echo '<tr><th>arquivos</th><td>' . count( $a['fotos'] ) . ' foto(s)' . ( $a['perfil'] ? ' + foto de perfil' : '' ) . '<br><small>' . esc_html( trailingslashit( mve_pasta_inscricoes() ) . get_post_meta( $p->ID, '_mve_uuid', true ) ) . '</small></td></tr>';
		echo '<tr><th>caminho</th><td>' . esc_html( implode( ' | ', (array) get_post_meta( $p->ID, '_mve_canais', true ) ) ) . '</td></tr>';
		echo '</tbody></table>';
	}, 'mve_inscricao', 'normal', 'high' );
} );

function mve_gravar_monumento( $mon, $c ) {
	$meta = array(
		'_mv3_lat' => $c['lat'], '_mv3_lng' => $c['lng'], '_mv3_city' => $c['bairro'], '_mv3_year' => $c['ano'],
		'_mv3_ytid' => $c['yt'] ? $c['yt'] : '', '_mve_drive' => isset( $c['drive'] ) ? $c['drive'] : '', '_mv3_ficha' => $c['ficha'], '_mve_raio' => $c['raio'], '_mve_fotos_credito' => $c['fcred'],
	);
	foreach ( $meta as $k => $v ) { update_post_meta( $mon, $k, $v ); }
}
function mve_gravar_fotos( $mon, $ids ) {
	$ids = array_values( array_map( 'intval', $ids ) );
	update_post_meta( $mon, '_mve_fotos', $ids );
	if ( $ids ) { set_post_thumbnail( $mon, $ids[0] ); } else { delete_post_thumbnail( $mon ); }
}

/* ================================================================== */
/* 2. Ajuste pela própria pessoa (link privado enviado por e-mail)      */
/* ================================================================== */
function mve_link_edicao_ok( $mon, $k ) {
	$p = get_post( $mon );
	if ( ! $p || 'mv3_monument' !== $p->post_type || '1' !== get_post_meta( $mon, '_mve_comunidade', true ) ) { return false; }
	$h   = (string) get_post_meta( $mon, '_mve_edit_hash', true );
	$ate = (int) get_post_meta( $mon, '_mve_edit_ate', true );
	return $h && strlen( (string) $k ) >= 20 && $ate > time() && hash_equals( $h, hash( 'sha256', (string) $k ) );
}
function mve_criar_link_edicao( $mon ) {
	$k = wp_generate_password( 32, false, false );
	update_post_meta( $mon, '_mve_edit_hash', hash( 'sha256', $k ) );
	update_post_meta( $mon, '_mve_edit_ate', time() + MVE_LINK_DIAS * DAY_IN_SECONDS );
	return add_query_arg( array( 'editar' => $mon, 'k' => $k ), home_url( '/mapa-wifi/' ) );
}

/** Dados para preencher o formulário de ajuste (a última versão enviada pela pessoa, se houver). */
function mve_editar( WP_REST_Request $r ) {
	$mon = (int) $r->get_param( 'id' );
	if ( ! mve_link_edicao_ok( $mon, $r->get_param( 'k' ) ) ) { return mve_erro( 'Este link de ajuste não vale mais. Responda o e-mail da equipe para pedir um novo.', 403 ); }
	$p = get_post_meta( $mon, '_mve_pendente', true );
	$v = is_array( $p ) ? $p : mve_atual( $mon );
	$ficha = mve_ficha_lista( $v['ficha'] );
	$fotos = array();
	foreach ( (array) $v['fotos'] as $f ) { $u = wp_get_attachment_image_url( $f, 'large' ); if ( $u ) { $fotos[] = array( 'id' => (int) $f, 'url' => $u ); } }
	return array(
		'ok' => true, 'ajuste' => (string) get_post_meta( $mon, '_mve_ajuste_msg', true ), 'enviado' => is_array( $p ),
		'nome' => $v['nome'], 'historia' => $v['hist'], 'ano' => $v['ano'], 'bairro' => $v['bairro'], 'yt' => $v['yt'], 'drive' => isset( $v['drive'] ) ? $v['drive'] : '',
		'lat' => $v['lat'], 'lng' => $v['lng'], 'raio' => $v['raio'], 'ficha' => $ficha, 'fotos' => $fotos, 'fotos_credito' => $v['fcred'],
		'artista' => array( 'nome' => $v['anome'], 'cidade' => $v['acid'], 'bio' => $v['abio'], 'credito' => $v['acred'], 'existente' => ( '1' === get_post_meta( $mon, '_mve_perfil_existente', true ) ),
			'foto' => $v['retrato'] ? wp_get_attachment_image_url( $v['retrato'], 'medium' ) : '' ),
	);
}

/** A pessoa envia os ajustes: ficam guardados à parte até a equipe dar o "ok final". */
function mve_ajustar( WP_REST_Request $r ) {
	$mon = (int) $r->get_param( 'id' );
	if ( ! mve_link_edicao_ok( $mon, $r->get_param( 'k' ) ) ) { return mve_erro( 'Este link de ajuste não vale mais. Responda o e-mail da equipe para pedir um novo.', 403 ); }
	$travado = ( '1' === get_post_meta( $mon, '_mve_perfil_existente', true ) ); // perfil escolhido da lista: não se edita por aqui
	$c = mve_campos( $r, true, $travado );
	if ( $c instanceof WP_REST_Response ) { return $c; }
	if ( $travado ) { $at = mve_atual( $mon ); foreach ( array( 'anome', 'acid', 'abio', 'acred' ) as $k ) { $c[ $k ] = $at[ $k ]; } }
	$art   = mve_artista_de( $mon );
	$antes = get_post_meta( $mon, '_mve_pendente', true );
	// fotos que continuam: só as que já pertencem a este monumento
	$validas = array_map( 'intval', (array) get_post_meta( $mon, '_mve_fotos', true ) );
	if ( is_array( $antes ) ) { $validas = array_merge( $validas, array_map( 'intval', (array) $antes['fotos'] ) ); }
	$manter = array_values( array_intersect( array_map( 'intval', (array) $r->get_param( 'manter' ) ), $validas ) );
	$novas  = array_slice( mve_arquivos( 'fotos' ), 0, max( 0, MVE_MAX_FOTOS - count( $manter ) ) );
	foreach ( $novas as $f ) { if ( ! mve_imagem_ok( $f ) ) { return mve_erro( 'Uma das fotos não pôde ser lida. Use JPG ou PNG.' ); } }
	if ( ! $c['yt'] && ! $c['drive'] && ! $manter && ! $novas ) { return mve_erro( 'Coloque um vídeo (YouTube ou Google Drive) ou pelo menos uma foto.' ); }
	$perfil = $travado ? array() : mve_arquivos( 'aFoto' );
	if ( $perfil && ! mve_imagem_ok( $perfil[0] ) ) { return mve_erro( 'A sua foto de perfil não pôde ser lida. Use JPG ou PNG.' ); }
	$lim = mve_limite( 'mve_aj_' . $mon, 10 ); // ajustes têm limite próprio, por monumento
	if ( $lim ) { return $lim; }

	$subidas = array();
	foreach ( $novas as $i => $f ) {
		$id = mve_subir( $f, $mon, $c['nome'] . ' — foto', $c['nome'] );
		if ( is_wp_error( $id ) ) { foreach ( $subidas as $x ) { wp_delete_attachment( $x, true ); } return mve_erro( 'Não foi possível guardar as fotos. Tente de novo.', 500 ); }
		$subidas[] = $id;
	}
	$retrato = 0;
	if ( $perfil ) {
		$retrato = mve_subir( $perfil[0], $art, $c['anome'], 'Retrato de ' . $c['anome'] );
		if ( is_wp_error( $retrato ) ) { foreach ( $subidas as $x ) { wp_delete_attachment( $x, true ); } return mve_erro( 'Não foi possível guardar a sua foto. Tente outra imagem.', 500 ); }
		$subidas[] = $retrato;
	} elseif ( is_array( $antes ) && ! empty( $antes['retrato'] ) ) {
		$retrato = (int) $antes['retrato'];
	}
	$c['fotos']   = array_merge( $manter, array_slice( $subidas, 0, count( $novas ) ) );
	$c['retrato'] = $retrato ? $retrato : (int) get_post_thumbnail_id( $art );
	$c['novas']   = $subidas;
	$c['enviado_em'] = current_time( 'mysql' );

	// arquivos de uma versão anterior (não aprovada) que não serão mais usados
	if ( is_array( $antes ) && ! empty( $antes['novas'] ) ) {
		foreach ( $antes['novas'] as $x ) { if ( ! in_array( (int) $x, $c['fotos'], true ) && (int) $x !== (int) $c['retrato'] ) { wp_delete_attachment( (int) $x, true ); } }
	}
	update_post_meta( $mon, '_mve_pendente', $c );
	mve_email_ajustado( $mon );
	return array( 'ok' => true );
}

/** "Ok final": aplica a versão ajustada e publica. */
function mve_aplicar_pendente( $mon ) {
	$p   = get_post_meta( $mon, '_mve_pendente', true );
	$art = mve_artista_de( $mon );
	if ( is_array( $p ) ) {
		$velhas = array_map( 'intval', (array) get_post_meta( $mon, '_mve_fotos', true ) );
		wp_update_post( array( 'ID' => $mon, 'post_title' => $p['nome'], 'post_content' => $p['hist'] ) );
		mve_gravar_monumento( $mon, $p );
		mve_gravar_fotos( $mon, $p['fotos'] );
		foreach ( array_diff( $velhas, array_map( 'intval', $p['fotos'] ) ) as $x ) { wp_delete_attachment( $x, true ); }
		if ( $art && '1' === get_post_meta( $art, '_mve_comunidade', true ) && '1' !== get_post_meta( $mon, '_mve_perfil_existente', true ) ) {
			wp_update_post( array( 'ID' => $art, 'post_title' => $p['anome'], 'post_content' => $p['abio'] ) );
			update_post_meta( $art, '_mv3_city', $p['acid'] );
			update_post_meta( $art, '_mv3_photo_credit', $p['acred'] );
			$velho = (int) get_post_thumbnail_id( $art );
			if ( ! empty( $p['retrato'] ) && (int) $p['retrato'] !== $velho ) {
				set_post_thumbnail( $art, (int) $p['retrato'] );
				if ( $velho ) { wp_delete_attachment( $velho, true ); }
			}
		}
		delete_post_meta( $mon, '_mve_pendente' );
	}
	wp_update_post( array( 'ID' => $mon, 'post_status' => 'publish' ) );
	if ( $art ) { wp_update_post( array( 'ID' => $art, 'post_status' => 'publish' ) ); }
	foreach ( array( '_mve_edit_hash', '_mve_edit_ate', '_mve_ajuste_msg', '_mve_checagem', '_mve_fora_por_ajuste' ) as $k ) { delete_post_meta( $mon, $k ); }
	update_post_meta( $mon, '_mve_conferido_em', current_time( 'mysql' ) );
	return is_array( $p );
}

/* ================================================================== */
/* 3. E-mails                                                           */
/* ================================================================== */
function mve_token( $id, $acao ) {
	return substr( hash_hmac( 'sha256', $id . '|' . $acao, wp_salt( 'auth' ) ), 0, 32 );
}
function mve_link_moderar( $id, $acao ) {
	return add_query_arg( array( 'mv_moderar' => $acao, 'id' => $id, 't' => mve_token( $id, $acao ) ), home_url( '/' ) );
}
/** Prévia de um monumento ainda não publicado, sem precisar estar logado: só abre com a chave do e-mail. */
function mve_link_previa( $id ) {
	return add_query_arg( array( 'post_type' => 'mv3_monument', 'p' => $id, 'mv_previa' => mve_token( $id, 'previa' ) ), home_url( '/' ) );
}
add_filter( 'redirect_canonical', function ( $url ) { return empty( $_GET['mv_previa'] ) ? $url : false; } ); // a prévia fica no endereço com a chave
add_filter( 'posts_results', function ( $posts, $q ) {
	if ( empty( $_GET['mv_previa'] ) || ! $q->is_main_query() || 1 !== count( $posts ) ) { return $posts; }
	$p = $posts[0];
	if ( 'mv3_monument' === $p->post_type && in_array( $p->post_status, array( 'pending', 'draft' ), true )
		&& hash_equals( mve_token( $p->ID, 'previa' ), sanitize_text_field( wp_unslash( $_GET['mv_previa'] ) ) ) ) {
		$posts[0]->post_status = 'publish'; // só nesta visualização; nada muda no banco
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'prévia de monumento' );
		add_action( 'send_headers', 'nocache_headers' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_action( 'wp_body_open', function () {
			echo '<div style="position:sticky;top:0;z-index:99999;background:#C67033;color:#fff;font:600 14px/1.4 Arial,sans-serif;padding:10px 16px;text-align:center">Prévia: este monumento ainda não está publicado.</div>';
		} );
	}
	return $posts;
}, 10, 2 );
function mve_btn( $txt, $url, $cheio = true ) {
	$s = $cheio ? 'background:#C67033;color:#ffffff;border:1px solid #C67033' : 'background:#ffffff;color:#141210;border:1px solid #c9c2b8';
	return '<a href="' . esc_url( $url ) . '" style="display:inline-block;margin:0 8px 8px 0;padding:13px 20px;border-radius:999px;font-weight:700;font-size:14px;text-decoration:none;' . $s . '">' . esc_html( $txt ) . '</a>';
}
function mve_lin( $rot, $val ) {
	return '<tr><td style="padding:9px 14px 9px 0;color:#7a726a;font-size:13px;vertical-align:top;white-space:nowrap">' . esc_html( $rot ) . '</td><td style="padding:9px 0;color:#141210;font-size:14px;line-height:1.5">' . $val . '</td></tr>';
}
function mve_caixa( $html, $cor = 'amarela' ) {
	$s = 'amarela' === $cor ? 'background:#fbf3e6;border:1px solid #f0d9b5;color:#5c4330' : 'background:#f4f1ec;border:1px solid #e3ddd4;color:#141210';
	return '<div style="margin:0 0 18px;padding:12px 14px;border-radius:10px;font-size:14px;line-height:1.55;' . $s . '">' . $html . '</div>';
}
function mve_email_html( $topo, $titulo, $miolo ) {
	return '<div style="background:#f2ede6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif">'
		. '<div style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e3ddd4">'
		. '<div style="background:#0A0A0A;padding:18px 24px"><div style="color:#C67033;font-size:11px;letter-spacing:2px;text-transform:uppercase">Monumentos Virtuais · Mapa Wi-Fi v2.0</div>'
		. '<div style="color:#F2EDE6;font-size:22px;font-weight:700;margin-top:6px">' . esc_html( $topo ) . '</div></div>'
		. '<div style="padding:22px 24px">' . ( $titulo ? '<p style="margin:0 0 14px;font-size:20px;font-weight:700;color:#141210">' . esc_html( $titulo ) . '</p>' : '' ) . $miolo . '</div></div></div>';
}
function mve_mandar( $para, $assunto, $html, $responder = '' ) {
	$h = array( 'Content-Type: text/html; charset=UTF-8', 'From: Monumentos Virtuais <nao-responda@' . mve_dominio() . '>' );
	if ( $responder ) { $h[] = 'Reply-To: ' . $responder; }
	return wp_mail( $para, $assunto, $html, $h );
}
function mve_miniaturas( $ids ) {
	$o = '';
	foreach ( array_slice( (array) $ids, 0, 8 ) as $fid ) {
		$t = wp_get_attachment_image_url( $fid, 'thumbnail' ); $g = wp_get_attachment_image_url( $fid, 'large' );
		if ( $t ) { $o .= '<a href="' . esc_url( $g ? $g : $t ) . '"><img src="' . esc_url( $t ) . '" width="84" height="84" alt="" style="width:84px;height:84px;object-fit:cover;border-radius:8px;margin:0 6px 6px 0;border:1px solid #e3ddd4"></a>'; }
	}
	return $o;
}
/** Nomes de uma função da ficha: o plugin do site devolve texto; aceita lista também. */
function mve_pessoas( $p ) {
	return is_array( $p ) ? implode( ', ', array_map( 'strval', $p ) ) : (string) $p;
}
/** Lê a ficha gravada ("Função: nomes" por linha) sem depender do formato interno do plugin do acervo. */
function mve_ficha_lista( $raw ) {
	$o = array();
	foreach ( preg_split( '/\r\n|\n|\r/', (string) $raw ) as $l ) {
		$l = trim( $l );
		if ( '' === $l || false === strpos( $l, ':' ) ) { continue; }
		list( $role, $p ) = array_map( 'trim', explode( ':', $l, 2 ) );
		if ( '' === $role ) { continue; }
		$o[] = array( 'role' => $role, 'people' => array_values( array_filter( array_map( 'trim', explode( ',', $p ) ), 'strlen' ) ) );
	}
	return $o;
}
function mve_ficha_html( $raw ) {
	$o = '<table style="border-collapse:collapse">';
	foreach ( mve_ficha_lista( $raw ) as $f ) {
		$o .= '<tr><td style="padding:5px 14px 5px 0;color:#7a726a;font-size:13px;vertical-align:top">' . esc_html( $f['role'] ) . '</td><td style="padding:5px 0;font-size:13px">' . esc_html( mve_pessoas( $f['people'] ?? '' ) ) . '</td></tr>';
	}
	return $o . '</table>';
}

/** Aviso para a equipe: monumento novo. */
function mve_email_inscrito( $mon, $modo ) {
	$v = mve_atual( $mon ); $art = mve_artista_de( $mon ); $email = mve_email_de( $mon ); $link = get_permalink( $mon ); $e = 'esc_html';
	$mapa = 'https://www.openstreetmap.org/?mlat=' . rawurlencode( $v['lat'] ) . '&mlon=' . rawurlencode( $v['lng'] ) . '#map=17/' . rawurlencode( $v['lat'] ) . '/' . rawurlencode( $v['lng'] );
	$retrato = $v['retrato'] ? wp_get_attachment_image_url( $v['retrato'], 'thumbnail' ) : '';
	$h  = '<p style="margin:0 0 16px;color:#7a726a;font-size:14px">por ' . $e( $v['anome'] ) . ' · ' . $e( $v['bairro'] ) . ( $v['ano'] ? ' · ' . $e( $v['ano'] ) : '' ) . '</p>';
	$aguarda = ( 'confirmar' === $modo );
	if ( $aguarda ) { $link = mve_link_previa( $mon ); }
	$h .= mve_btn( $aguarda ? 'Ver como vai ficar' : 'Abrir o monumento para checar', $link );
	$h .= '<p style="margin:2px 0 18px;font-size:12px;color:#7a726a;word-break:break-all">Link: <a href="' . esc_url( $link ) . '" style="color:#A35A27">' . $e( $link ) . '</a></p>';
	if ( $aguarda ) {
		$h .= mve_caixa( 'Ainda <b>não está no ar</b>. A pessoa escolheu na lista o perfil que já existe de <b>' . $e( $v['anome'] ) . '</b>, mas o e-mail dela (' . $e( $email ) . ') não confere com o guardado nesse perfil (ou o perfil ainda não tem e-mail guardado). Confira se é mesmo a artista: se for, confirme e o monumento entra no ar ligado ao perfil; da próxima vez, com o mesmo e-mail, entra direto. Na dúvida, responda este e-mail para falar com a pessoa.' );
		$h .= mve_btn( 'Confirmar e publicar', mve_link_moderar( $mon, 'vincular' ) ) . mve_btn( 'Pedir ajuste à pessoa', mve_link_moderar( $mon, 'ajuste' ), false ) . mve_btn( 'Não é a artista: tirar', mve_link_moderar( $mon, 'tirar' ), false );
	} else {
		$h .= mve_caixa( 'Ele <b>já está no ar</b>: no mapa, em Monumentos e em Artistas. Se estiver tudo certo, não precisa fazer nada. Se algo precisar mudar, use <b>Pedir ajuste</b>: a pessoa recebe a sua mensagem e um link para editar, e os ajustes só entram no ar quando você der o ok final. Para conversar com ela, é só responder este e-mail.' );
		$h .= mve_btn( 'Pedir ajuste à pessoa', mve_link_moderar( $mon, 'ajuste' ) ) . mve_btn( 'Tirar do ar', mve_link_moderar( $mon, 'tirar' ), false ) . mve_btn( 'Editar no WordPress', admin_url( 'post.php?post=' . $mon . '&action=edit' ), false );
	}
	$h .= '<table style="width:100%;border-collapse:collapse;margin-top:14px;border-top:1px solid #eee7dd">';
	$h .= mve_lin( 'Monumento', '<a href="' . esc_url( $link ) . '" style="color:#A35A27">' . $e( $v['nome'] ) . '</a>' );
	$h .= mve_lin( 'Artista', '<a href="' . esc_url( get_permalink( $art ) ) . '" style="color:#A35A27">' . $e( $v['anome'] ) . '</a> <span style="color:#7a726a">(' . $e( array( 'novo' => 'perfil novo', 'mesmo_email' => 'perfil já existente, mesmo e-mail', 'existente' => 'escolheu o próprio perfil na lista; o e-mail confere', 'confirmar' => 'escolheu na lista um perfil que já existe: confirmar' )[ $modo ] ) . ')</span>'
		. ( 'novo' === $modo && get_posts( array( 'post_type' => 'mv3_artist', 'post_status' => 'publish', 'title' => $v['anome'], 'post__not_in' => array( $art ), 'numberposts' => 1, 'fields' => 'ids' ) ) ? '<br><span style="color:#A35A27">Atenção: já existia um perfil com este mesmo nome em Artistas.</span>' : '' ) );
	$h .= mve_lin( 'E-mail do artista', '<a href="mailto:' . esc_attr( $email ) . '" style="color:#A35A27">' . $e( $email ) . '</a>' );
	$h .= mve_lin( 'Território', '<a href="' . esc_url( $mapa ) . '" style="color:#A35A27">' . $e( $v['lat'] . ', ' . $v['lng'] ) . '</a> · raio de ' . $e( mve_raio_txt( $v['raio'] ) ) );
	$h .= mve_lin( 'Vídeo', $v['yt'] ? '<a href="' . esc_url( 'https://www.youtube.com/watch?v=' . $v['yt'] ) . '" style="color:#A35A27">youtube.com/watch?v=' . $e( $v['yt'] ) . '</a>' : '—' );
	if ( $v['drive'] ) { $h .= mve_lin( 'Vídeo no Drive', '<a href="' . esc_url( $v['drive'] ) . '" style="color:#A35A27;word-break:break-all">' . $e( $v['drive'] ) . '</a><br><span style="color:#A35A27">O YouTube bloqueou a obra: o vídeo precisa ser publicado pela equipe.</span>' ); }
	$h .= mve_lin( 'Fotos', $v['fotos'] ? count( $v['fotos'] ) . ( $v['fcred'] ? ' · autoria: ' . $e( $v['fcred'] ) : '' ) . '<br>' . mve_miniaturas( $v['fotos'] ) : '—' );
	$h .= mve_lin( 'História', nl2br( $e( $v['hist'] ) ) );
	$h .= mve_lin( 'Ficha técnica', mve_ficha_html( $v['ficha'] ) );
	$h .= mve_lin( 'Minibio', nl2br( $e( $v['abio'] ) ) );
	$h .= mve_lin( 'Foto do perfil', ( $retrato ? '<img src="' . esc_url( $retrato ) . '" width="72" height="72" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover"><br>' : '' ) . 'autoria: ' . $e( $v['acred'] ? $v['acred'] : 'do próprio artista' ) );
	$h .= '</table>';
	$nome_resp = str_replace( array( "\r", "\n", '<', '>', ',' ), '', $v['anome'] );
	return mve_mandar( MVE_PARA, ( $aguarda ? 'Monumento inscrito (confirmar artista): ' : 'Monumento inscrito: ' ) . $v['nome'], mve_email_html( 'Monumento inscrito', $v['nome'], $h ), $nome_resp . ' <' . $email . '>' );
}

/** Para a pessoa: o que precisa ajustar + link para editar. */
function mve_email_pedido( $mon, $msg, $link, $fora ) {
	$v = mve_atual( $mon ); $e = 'esc_html';
	$primeiro = trim( strtok( $v['anome'], ' ' ) );
	$h  = '<p style="margin:0 0 14px;font-size:15px;line-height:1.55;color:#141210">Olá, ' . $e( $primeiro ) . '! A equipe dos Monumentos Virtuais conferiu o seu monumento <b>' . $e( $v['nome'] ) . '</b> e pediu alguns ajustes:</p>';
	$h .= mve_caixa( nl2br( $e( $msg ) ) );
	$h .= '<p style="margin:0 0 16px;font-size:14px;color:#5c574f;line-height:1.55">' . ( $fora ? 'Enquanto isso, ele fica <b>fora do ar</b>. Assim que você enviar os ajustes e a equipe conferir, ele volta.' : 'Ele continua no ar enquanto isso. Os ajustes entram assim que a equipe conferir.' ) . '</p>';
	$h .= mve_btn( 'Editar meu monumento', $link );
	$h .= '<p style="margin:10px 0 0;font-size:12px;color:#7a726a;line-height:1.5">O formulário abre já preenchido. O link é só seu e vale por ' . MVE_LINK_DIAS . ' dias. Alguma dúvida? É só responder este e-mail.</p>';
	return mve_mandar( mve_email_de( $mon ), 'Ajuste no seu monumento: ' . $v['nome'], mve_email_html( 'Pedido de ajuste', '', $h ), 'Monumentos Virtuais <' . MVE_PARA . '>' );
}

/** Para a equipe: a pessoa enviou os ajustes (antes → depois). */
function mve_email_ajustado( $mon ) {
	$a = mve_atual( $mon ); $p = get_post_meta( $mon, '_mve_pendente', true ); $e = 'esc_html';
	$campos = array( 'nome' => 'Nome do monumento', 'hist' => 'História', 'ano' => 'Ano', 'bairro' => 'Bairro / cidade', 'yt' => 'Vídeo', 'drive' => 'Vídeo no Drive', 'raio' => 'Território', 'ficha' => 'Ficha técnica', 'fcred' => 'Autoria das fotos', 'anome' => 'Nome do artista', 'acid' => 'Cidade do artista', 'abio' => 'Minibio', 'acred' => 'Autoria da foto do perfil' );
	$fmt = function ( $k, $x ) use ( $e ) {
		if ( 'ficha' === $k ) { return mve_ficha_html( $x ); }
		if ( 'raio' === $k ) { return $e( mve_raio_txt( $x ) ); }
		if ( 'yt' === $k ) { return $x ? 'youtube.com/watch?v=' . $e( $x ) : '—'; }
		return '' === (string) $x ? '—' : nl2br( $e( $x ) );
	};
	$mud = '';
	foreach ( $campos as $k => $rot ) {
		if ( (string) ( $a[ $k ] ?? '' ) === (string) ( $p[ $k ] ?? '' ) ) { continue; }
		$mud .= mve_lin( $rot, '<div style="color:#9a8f84;text-decoration:line-through;margin-bottom:6px">' . $fmt( $k, $a[ $k ] ?? '' ) . '</div><div>' . $fmt( $k, $p[ $k ] ?? '' ) . '</div>' );
	}
	if ( abs( $a['lat'] - $p['lat'] ) > 0.00001 || abs( $a['lng'] - $p['lng'] ) > 0.00001 ) {
		$mud .= mve_lin( 'Lugar no mapa', 'mudou para <a href="' . esc_url( 'https://www.openstreetmap.org/?mlat=' . $p['lat'] . '&mlon=' . $p['lng'] . '#map=17/' . $p['lat'] . '/' . $p['lng'] ) . '" style="color:#A35A27">' . $e( round( $p['lat'], 5 ) . ', ' . round( $p['lng'], 5 ) ) . '</a>' );
	}
	if ( array_values( $a['fotos'] ) !== array_values( array_map( 'intval', $p['fotos'] ) ) ) {
		$mud .= mve_lin( 'Fotos', count( $p['fotos'] ) . ' foto(s) agora<br>' . mve_miniaturas( $p['fotos'] ) );
	}
	if ( (int) $p['retrato'] !== (int) $a['retrato'] ) {
		$u = wp_get_attachment_image_url( $p['retrato'], 'thumbnail' );
		$mud .= mve_lin( 'Foto do perfil', $u ? '<img src="' . esc_url( $u ) . '" width="72" height="72" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover">' : 'nova foto' );
	}
	$pedido = (string) get_post_meta( $mon, '_mve_ajuste_msg', true );
	$h  = '<p style="margin:0 0 14px;font-size:15px;color:#141210">' . $e( $p['anome'] ) . ' enviou os ajustes de <b>' . $e( $p['nome'] ) . '</b>.</p>';
	if ( $pedido ) { $h .= mve_caixa( '<span style="font-size:12px;color:#7a726a">O que você pediu:</span><br>' . nl2br( $e( $pedido ) ), 'cinza' ); }
	$h .= '<p style="margin:0 0 6px;font-size:13px;color:#7a726a;text-transform:uppercase;letter-spacing:1px">O que mudou</p>';
	$h .= $mud ? '<table style="width:100%;border-collapse:collapse;border-top:1px solid #eee7dd;margin-bottom:16px">' . $mud . '</table>' : '<p style="font-size:14px">Nenhum campo foi alterado.</p>';
	$h .= mve_caixa( 'Os ajustes <b>ainda não estão no ar</b>. Confira e dê o ok final para publicar.' );
	$h .= mve_btn( 'Dar ok final e publicar', mve_link_moderar( $mon, 'final' ) ) . mve_btn( 'Pedir outro ajuste', mve_link_moderar( $mon, 'ajuste' ), false );
	return mve_mandar( MVE_PARA, 'Monumento ajustado: ' . $p['nome'], mve_email_html( 'Monumento ajustado', '', $h ), str_replace( array( "\r", "\n", '<', '>', ',' ), '', $p['anome'] ) . ' <' . mve_email_de( $mon ) . '>' );
}

/** Para a pessoa: está no ar. */
function mve_email_no_ar( $mon ) {
	$v = mve_atual( $mon ); $e = 'esc_html'; $primeiro = trim( strtok( $v['anome'], ' ' ) );
	$h  = '<p style="margin:0 0 16px;font-size:15px;line-height:1.55;color:#141210">Olá, ' . $e( $primeiro ) . '! A equipe conferiu e o seu monumento <b>' . $e( $v['nome'] ) . '</b> está no ar, no mapa dos Monumentos Virtuais.</p>';
	$h .= mve_btn( 'Ver meu monumento', get_permalink( $mon ) );
	$h .= '<p style="margin:10px 0 0;font-size:12px;color:#7a726a">Obrigado por erguer este monumento.</p>';
	return mve_mandar( mve_email_de( $mon ), 'Seu monumento está no ar: ' . $v['nome'], mve_email_html( 'Está no ar', '', $h ), 'Monumentos Virtuais <' . MVE_PARA . '>' );
}

/* ================================================================== */
/* 4. Páginas dos botões do e-mail (sempre pedem confirmação)           */
/* ================================================================== */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['mv_moderar'] ) ) { return; }
	$acao = sanitize_key( wp_unslash( $_GET['mv_moderar'] ) );
	$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	$t    = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
	$post = get_post( $id );
	if ( 'restaurar' === $acao ) { mve_pagina_restaurar( $post, $t ); }
	if ( ! in_array( $acao, array( 'ok', 'ajuste', 'final', 'tirar', 'voltar', 'vincular' ), true ) || ! $post || 'mv3_monument' !== $post->post_type
		|| '1' !== get_post_meta( $id, '_mve_comunidade', true ) || ! hash_equals( mve_token( $id, $acao ), $t ) ) {
		mve_pagina( 'Link inválido', '<p>Este link não vale mais ou foi copiado pela metade.</p>' );
	}
	$nome  = '<b>' . esc_html( $post->post_title ) . '</b>';
	$ver   = '<p style="margin-top:18px"><a href="' . esc_url( get_permalink( $id ) ) . '" style="color:#E3A46F">Ver o monumento</a></p>';
	$email = mve_email_de( $id );
	$post_ok = ( 'POST' === $_SERVER['REQUEST_METHOD'] );
	$btn = function ( $txt ) { return '<button type="submit" style="margin-top:12px;padding:13px 22px;border-radius:999px;border:0;background:#C67033;color:#fff;font-weight:700;font-size:15px;cursor:pointer">' . esc_html( $txt ) . '</button>'; };

	if ( 'ajuste' === $acao ) {
		if ( ! $post_ok ) {
			$ant = (string) get_post_meta( $id, '_mve_ajuste_msg', true );
			mve_pagina( 'Pedir ajuste', '<p style="margin:0 0 14px">' . $nome . '<br><span style="opacity:.75">A mensagem vai para ' . esc_html( $email ) . ', com um link para a pessoa editar o monumento no mesmo formulário, já preenchido. Os ajustes só entram no ar quando você der o ok final.</span></p>'
				. '<form method="post"><label for="msg" style="display:block;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#C67033;margin-bottom:6px">O que precisa mudar</label>'
				. '<textarea id="msg" name="msg" required rows="7" style="width:100%;box-sizing:border-box;padding:12px;border-radius:10px;border:1px solid #3a342e;background:#141210;color:#F2EDE6;font:15px/1.5 Arial,sans-serif">' . esc_textarea( $ant ) . '</textarea>'
				. '<label style="display:flex;gap:9px;align-items:flex-start;margin-top:12px;font-size:14px"><input type="checkbox" name="tirar" value="1" checked style="margin-top:3px;accent-color:#C67033"> Tirar do ar enquanto a pessoa ajusta</label>'
				. $btn( 'Enviar pedido de ajuste' ) . '</form>' . $ver );
		}
		$msg = isset( $_POST['msg'] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST['msg'] ) ) ), 0, 2000 ) : '';
		if ( '' === $msg ) { mve_pagina( 'Falta a mensagem', '<p>Escreva o que precisa mudar. <a href="javascript:history.back()" style="color:#E3A46F">Voltar</a></p>' ); }
		$fora = ! empty( $_POST['tirar'] );
		update_post_meta( $id, '_mve_ajuste_msg', $msg );
		$link = mve_criar_link_edicao( $id );
		if ( $fora ) { mve_tirar( $id ); update_post_meta( $id, '_mve_fora_por_ajuste', '1' ); }
		$ok = mve_email_pedido( $id, $msg, $link, $fora );
		mve_pagina( $ok ? 'Pedido enviado' : 'Pedido salvo, mas o e-mail falhou', '<p>' . ( $ok ? 'A mensagem foi para ' . esc_html( $email ) . '.' : 'O servidor não conseguiu mandar o e-mail para ' . esc_html( $email ) . '.' ) . ( $fora ? ' ' . $nome . ' está fora do ar até você dar o ok final.' : ' ' . $nome . ' continua no ar.' ) . '</p>'
			. '<p style="margin-top:14px;font-size:14px;opacity:.85">Se preferir mandar também por WhatsApp, este é o link de edição da pessoa (vale por ' . MVE_LINK_DIAS . ' dias):</p>'
			. '<p style="word-break:break-all;font-size:13px;background:#141210;border:1px solid #3a342e;border-radius:8px;padding:10px">' . esc_html( $link ) . '</p>' . $ver );
	}

	if ( 'vincular' === $acao ) {
		$art = mve_artista_de( $id );
		if ( ! $post_ok ) {
			mve_pagina( 'Confirmar e publicar?', '<p style="margin:0 0 6px">' . $nome . '</p><p>O monumento entra no ar ligado ao perfil de <b>' . esc_html( get_the_title( $art ) ) . '</b>, e o e-mail ' . esc_html( $email ) . ' passa a valer para esse perfil: os próximos envios com ele entram direto.</p><form method="post">' . $btn( 'Confirmar e publicar' ) . '</form>' );
		}
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		if ( $art && ! get_post_meta( $art, '_mve_email', true ) ) { update_post_meta( $art, '_mve_email', strtolower( $email ) ); }
		delete_post_meta( $id, '_mve_aguarda_vinculo' );
		mve_email_no_ar( $id );
		mve_pagina( 'Publicado', '<p>' . $nome . ' está no ar, na lista de obras de ' . esc_html( get_the_title( $art ) ) . '. Avisamos ' . esc_html( $email ) . '.</p>' . $ver );
	}

	if ( 'final' === $acao ) {
		$p = get_post_meta( $id, '_mve_pendente', true );
		if ( ! $post_ok ) {
			$txt = is_array( $p ) ? esc_html( $p['anome'] ) . ' enviou os ajustes em ' . esc_html( mysql2date( 'd/m/Y \à\s H:i', $p['enviado_em'] ) ) . '. Ao confirmar, a versão ajustada entra no ar e a pessoa recebe um e-mail avisando.'
				: 'A pessoa ainda não enviou ajustes. Se confirmar, o monumento fica no ar como está.';
			mve_pagina( 'Dar ok final?', '<p style="margin:0 0 6px">' . $nome . '</p><p>' . $txt . '</p><form method="post">' . $btn( 'Dar ok final e publicar' ) . '</form>' . $ver );
		}
		$tinha = mve_aplicar_pendente( $id );
		if ( $tinha ) { mve_email_no_ar( $id ); }
		mve_pagina( 'Publicado', '<p>' . esc_html( get_the_title( $id ) ) . ' está no ar' . ( $tinha ? ' com os ajustes. Avisamos ' . esc_html( $email ) . '.' : '.' ) . '</p>' . $ver );
	}

	if ( 'ok' === $acao ) { // e-mails antigos
		if ( ! $post_ok ) { mve_pagina( 'Marcar como conferido?', '<p>' . $nome . ' continua no ar.</p><form method="post">' . $btn( 'Marcar como conferido' ) . '</form>' . $ver ); }
		delete_post_meta( $id, '_mve_checagem' );
		update_post_meta( $id, '_mve_conferido_em', current_time( 'mysql' ) );
		mve_pagina( 'Conferido', '<p>' . $nome . ' está no ar.</p>' . $ver );
	}

	if ( 'tirar' === $acao ) {
		if ( ! $post_ok ) { mve_pagina( 'Tirar do ar?', '<p style="margin:0 0 6px">' . $nome . '</p><p>Ele sai do mapa e de Monumentos. Se o artista não tiver outra obra no ar, o perfil também sai de Artistas. Nada é apagado e dá para desfazer.</p><form method="post">' . $btn( 'Tirar do ar' ) . '</form>' . $ver ); }
		mve_tirar( $id );
		mve_pagina( 'Fora do ar', '<p>' . $nome . ' saiu do mapa. Nada foi apagado: ele fica como rascunho no WordPress.</p><p><a href="' . esc_url( mve_link_moderar( $id, 'voltar' ) ) . '" style="color:#E3A46F">Desfazer: colocar de volta no ar</a></p>' );
	}

	if ( 'voltar' === $acao ) {
		if ( ! $post_ok ) { mve_pagina( 'Colocar de volta no ar?', '<p>' . $nome . ' (e o perfil do artista) voltam para o mapa, para Monumentos e para Artistas.</p><form method="post">' . $btn( 'Colocar de volta no ar' ) . '</form>' ); }
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$art = mve_artista_de( $id );
		if ( $art ) { wp_update_post( array( 'ID' => $art, 'post_status' => 'publish' ) ); }
		mve_pagina( 'De volta no ar', '<p>' . $nome . ' voltou para o mapa.</p>' . $ver );
	}
} );

/** "Restaurar e publicar": publica uma inscrição que ficou guardada. Sempre pergunta antes. */
function mve_pagina_restaurar( $rec, $t ) {
	if ( ! $rec || 'mve_inscricao' !== $rec->post_type || ! hash_equals( mve_token( $rec->ID, 'restaurar' ), (string) $t ) ) {
		mve_pagina( 'Link inválido', '<p>Este link não vale mais ou foi copiado pela metade.</p>' );
	}
	$rid  = $rec->ID;
	$nome = '<b>' . esc_html( $rec->post_title ) . '</b>';
	$btn  = '<button type="submit" style="margin-top:12px;padding:13px 22px;border-radius:999px;border:0;background:#C67033;color:#fff;font-weight:700;font-size:15px;cursor:pointer">Restaurar e publicar</button>';
	if ( 'publicada' === get_post_meta( $rid, '_mve_estado', true ) ) {
		$mon = (int) get_post_meta( $rid, '_mve_monumento', true );
		mve_pagina( 'Já está publicada', '<p>' . $nome . ' já foi publicada.</p>' . ( $mon ? '<p><a href="' . esc_url( get_permalink( $mon ) ) . '" style="color:#E3A46F">Ver o monumento</a></p>' : '' ) );
	}
	$a = mve_arquivos_de_registro( $rid );
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		mve_pagina( 'Restaurar e publicar?', '<p style="margin:0 0 6px">' . $nome . '</p><p>A inscrição está guardada com ' . count( $a['fotos'] ) . ' foto(s)' . ( $a['perfil'] ? ' e a foto de perfil' : '' ) . '. Ao confirmar, ela é publicada como se tivesse entrado normalmente, e a pessoa recebe um e-mail avisando.</p>'
			. '<p style="font-size:13px;opacity:.75">Motivo de ter ficado guardada: ' . esc_html( (string) get_post_meta( $rid, '_mve_motivo', true ) ) . '</p><form method="post">' . $btn . '</form>' );
	}
	try {
		$res = mve_processar( $rid, false );
	} catch ( Throwable $t ) {
		$msg = $t->getMessage() . ' (' . basename( $t->getFile() ) . ':' . $t->getLine() . ')';
		mve_log( 'RESTAURAR FALHOU #' . $rid . ': ' . $msg );
		mve_estado( $rid, 'falhou', array( '_mve_motivo' => 'Restaurar falhou: ' . $msg ) );
		mve_pagina( 'Ainda não deu', '<p>' . $nome . ' continua guardada; nada se perdeu.</p><p style="font-size:14px">Erro: ' . esc_html( $msg ) . '</p><p style="font-size:14px">Copie esta mensagem e mande para quem cuida do site. Os dados completos estão no WordPress, em <b>Inscrições do mapa</b>.</p>' );
	}
	if ( $res instanceof WP_REST_Response ) {
		$d = $res->get_data();
		mve_pagina( 'Falta um dado', '<p>' . $nome . ' continua guardada.</p><p>' . esc_html( isset( $d['erro'] ) ? $d['erro'] : '' ) . '</p><p style="font-size:14px">Corrija no WordPress, em <b>Inscrições do mapa</b>, ou responda o e-mail para falar com a pessoa.</p>' );
	}
	$mon = (int) $res['id'];
	try {
		if ( empty( $res['aguarda'] ) ) { mve_email_no_ar( $mon ); } else { mve_email_inscrito( $mon, 'confirmar' ); }
	} catch ( Throwable $t ) { mve_log( 'E-MAIL APÓS RESTAURAR #' . $mon . ' FALHOU: ' . $t->getMessage() ); }
	mve_pagina( 'Publicado', '<p>' . $nome . ' está no ar' . ( empty( $res['aguarda'] ) ? ' e a pessoa recebeu um e-mail avisando' : ' (aguardando a confirmação do perfil de artista: veja o e-mail "confirmar artista")' ) . '.</p><p><a href="' . esc_url( get_permalink( $mon ) ) . '" style="color:#E3A46F">Ver o monumento</a></p>' );
}

function mve_tirar( $id ) {
	wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
	$art = mve_artista_de( $id );
	if ( $art && '1' === get_post_meta( $art, '_mve_comunidade', true ) ) {
		$outras = get_posts( array( 'post_type' => 'mv3_monument', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_mv3_artist_id', 'meta_value' => $art ) );
		if ( ! $outras ) { wp_update_post( array( 'ID' => $art, 'post_status' => 'draft' ) ); }
	}
}

function mve_pagina( $titulo, $html ) {
	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $titulo ) . ' · Monumentos Virtuais</title></head>'
		. '<body style="margin:0;background:#0A0A0A;color:#F2EDE6;font-family:Arial,Helvetica,sans-serif"><div style="max-width:560px;margin:10vh auto;padding:0 20px 40px">'
		. '<p style="color:#C67033;font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px">Monumentos Virtuais · checagem</p>'
		. '<h1 style="font-size:26px;margin:0 0 16px">' . esc_html( $titulo ) . '</h1><div style="color:#cfc7bd;line-height:1.6">' . $html . '</div></div></body></html>';
	exit;
}

/* ================================================================== */
/* 5. O que o mapa e a página do monumento mostram                      */
/* ================================================================== */
add_filter( 'rest_post_dispatch', function ( $res, $server, $req ) {
	if ( ! ( $res instanceof WP_REST_Response ) || $res->is_error() ) { return $res; }
	$rota = $req->get_route();
	$data = $res->get_data();
	if ( '/mv3/v1/map' === $rota && is_array( $data ) ) {
		foreach ( $data as $i => $o ) {
			$id = is_array( $o ) && isset( $o['id'] ) ? (int) $o['id'] : 0;
			if ( $id && '1' === get_post_meta( $id, '_mve_comunidade', true ) ) {
				$data[ $i ]['comunidade'] = true;
				$data[ $i ]['raio']       = (int) get_post_meta( $id, '_mve_raio', true );
			}
		}
		$res->set_data( $data );
	} elseif ( 0 === strpos( $rota, '/mv3/v1/monuments/' ) && is_array( $data ) ) {
		$p = get_posts( array( 'post_type' => 'mv3_monument', 'name' => sanitize_title( $req['slug'] ), 'numberposts' => 1 ) );
		if ( $p && '1' === get_post_meta( $p[0]->ID, '_mve_comunidade', true ) ) {
			$id = $p[0]->ID;
			$data['comunidade']    = true;
			$data['raio']          = (int) get_post_meta( $id, '_mve_raio', true );
			$data['fotos']         = array_values( array_filter( array_map( function ( $f ) { return wp_get_attachment_image_url( $f, 'large' ); }, (array) get_post_meta( $id, '_mve_fotos', true ) ) ) );
			$data['fotos_credito'] = get_post_meta( $id, '_mve_fotos_credito', true );
			$res->set_data( $data );
		}
	}
	return $res;
}, 10, 3 );

// Página do monumento: selo "inscrito pela comunidade" e as fotos com a autoria.
add_filter( 'the_content', function ( $c ) {
	if ( ! is_singular( 'mv3_monument' ) || ! in_the_loop() || ! is_main_query() ) { return $c; }
	$id = get_the_ID();
	if ( '1' !== get_post_meta( $id, '_mve_comunidade', true ) ) { return $c; }
	$topo  = '<p class="mve-selo" style="display:inline-block;margin:0 0 14px;padding:4px 10px;border-radius:999px;border:1px solid #2F4659;color:#9EC3E6;font-size:12px;letter-spacing:.04em">inscrito pela comunidade</p>';
	$fotos = (array) get_post_meta( $id, '_mve_fotos', true );
	$g = '';
	$fotos = array_values( array_filter( array_map( 'intval', $fotos ), 'wp_get_attachment_image_url' ) );
	if ( $fotos ) {
		$n    = count( $fotos );
		$cred = get_post_meta( $id, '_mve_fotos_credito', true );
		$g   .= $n > 1 ? '<p class="mve-fotos-tit" style="margin:26px 0 0;font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.75">Galeria · ' . $n . ' fotos</p>' : '';
		$g   .= '<div class="mve-fotos" data-cred="' . esc_attr( $cred ? 'fotos: ' . $cred : '' ) . '" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin:' . ( $n > 1 ? '10px' : '22px' ) . ' 0 6px">';
		foreach ( $fotos as $i => $f ) {
			$u   = wp_get_attachment_image_url( $f, 'large' );
			$rot = $n > 1 ? 'Abrir a galeria na foto ' . ( $i + 1 ) . ' de ' . $n : 'Ampliar a foto';
			$g  .= '<a href="' . esc_url( $u ) . '" aria-label="' . esc_attr( $rot ) . '" style="display:block;cursor:zoom-in">' . wp_get_attachment_image( $f, 'medium', false, array( 'style' => 'width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;display:block', 'loading' => 'lazy', 'alt' => '' ) ) . '</a>';
		}
		$g .= '</div>';
		add_action( 'wp_footer', 'mve_galeria_script' );
		if ( $cred ) { $g .= '<p class="mve-fotos-cred" style="margin:0 0 10px;font-size:12px;opacity:.7">fotos: ' . esc_html( $cred ) . '</p>'; }
	}
	return $topo . $c . $g;
}, 20 );

/** Galeria das fotos do monumento: abre por cima da página, com setas, contador e X para fechar. */
function mve_galeria_script() {
	static $feito = false;
	if ( $feito ) { return; }
	$feito = true;
	?>
<style id="mve-galeria-css">
.mve-lb{position:fixed;inset:0;z-index:100000;background:rgba(8,8,8,.97);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .18s;touch-action:pan-y}
.mve-lb.on{opacity:1}
.mve-lb figure{margin:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 72px 18px;box-sizing:border-box;width:100%;height:100%}
.mve-lb img{display:block;width:auto;height:auto;max-width:100%;max-height:calc(100vh - 120px);max-height:calc(100dvh - 120px);border-radius:6px;user-select:none;-webkit-user-drag:none}
.mve-lb figcaption{color:#cfc7bd;font:13px/1.4 Arial,Helvetica,sans-serif;margin-top:12px;text-align:center}
.mve-lb button{position:absolute;display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:999px;border:1px solid rgba(242,237,230,.28);background:rgba(20,18,16,.72);color:#F2EDE6;cursor:pointer;padding:0;font:400 26px/1 Arial,sans-serif}
.mve-lb button:hover,.mve-lb button:focus-visible{border-color:#C67033;background:#C67033;outline:none}
.mve-lb .x{top:12px;right:12px}
.mve-lb .ant{left:12px;top:50%;margin-top:-24px}
.mve-lb .prox{right:12px;top:50%;margin-top:-24px}
.mve-lb .cont{position:absolute;top:24px;left:20px;color:#F2EDE6;font:600 13px/1 Arial,sans-serif;letter-spacing:.08em}
@media (max-width:640px){.mve-lb figure{padding:64px 10px 78px}.mve-lb img{max-height:calc(100vh - 180px);max-height:calc(100dvh - 180px)}.mve-lb .ant,.mve-lb .prox{top:auto;bottom:14px;margin:0}}
</style>
<script id="mve-galeria-js">
(function(){
  var grade = document.querySelectorAll('.mve-fotos');
  Array.prototype.forEach.call(grade, function(g){
    var links = Array.prototype.slice.call(g.querySelectorAll('a'));
    var cred = g.getAttribute('data-cred') || '';
    links.forEach(function(a, i){ a.addEventListener('click', function(e){ e.preventDefault(); abrir(links, i, cred, a); }); });
  });
  function abrir(links, i, cred, origem){
    var n = links.length, x0 = null, antes = document.documentElement.style.overflow;
    var lb = document.createElement('div');
    lb.className = 'mve-lb'; lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-modal', 'true'); lb.setAttribute('aria-label', n > 1 ? 'Galeria de fotos' : 'Foto ampliada');
    lb.innerHTML = '<figure><img alt=""><figcaption></figcaption></figure>'
      + '<button type="button" class="x" aria-label="Fechar">\u00d7</button>'
      + (n > 1 ? '<span class="cont" aria-live="polite"></span><button type="button" class="ant" aria-label="Foto anterior">\u2039</button><button type="button" class="prox" aria-label="Pr\u00f3xima foto">\u203a</button>' : '');
    var img = lb.querySelector('img'), cap = lb.querySelector('figcaption'), cont = lb.querySelector('.cont');
    function mostrar(k){
      i = (k + n) % n;
      img.src = links[i].href;
      if (cont) cont.textContent = (i + 1) + ' / ' + n;
      cap.textContent = cred; cap.style.display = cred ? '' : 'none';
      if (n > 1) { [i + 1, i - 1].forEach(function(j){ var p = new Image(); p.src = links[(j + n) % n].href; }); }
    }
    function fechar(){
      document.removeEventListener('keydown', tecla);
      document.documentElement.style.overflow = antes;
      lb.remove(); try { origem.focus(); } catch (e) {}
    }
    function tecla(e){
      if (e.key === 'Escape') fechar();
      else if (n > 1 && e.key === 'ArrowRight') mostrar(i + 1);
      else if (n > 1 && e.key === 'ArrowLeft') mostrar(i - 1);
    }
    lb.querySelector('.x').onclick = fechar;
    if (n > 1) { lb.querySelector('.ant').onclick = function(){ mostrar(i - 1); }; lb.querySelector('.prox').onclick = function(){ mostrar(i + 1); }; }
    lb.addEventListener('click', function(e){ if (e.target === lb || e.target.tagName === 'FIGURE') fechar(); });
    lb.addEventListener('touchstart', function(e){ x0 = e.touches[0].clientX; }, { passive: true });
    lb.addEventListener('touchend', function(e){ if (x0 === null || n < 2) return; var d = e.changedTouches[0].clientX - x0; x0 = null; if (Math.abs(d) > 45) mostrar(d < 0 ? i + 1 : i - 1); });
    document.addEventListener('keydown', tecla);
    document.documentElement.style.overflow = 'hidden';
    document.body.appendChild(lb); mostrar(i);
    requestAnimationFrame(function(){ lb.classList.add('on'); });
    lb.querySelector('.x').focus();
  }
})();
</script>
	<?php
}

/* ================================================================== */
/* 6. Números que se atualizam sozinhos: [mve_contagem tipo="monumentos|artistas|pontos"]   */
/* ================================================================== */
add_shortcode( 'mve_contagem', function ( $atts ) {
	$t = isset( $atts['tipo'] ) ? $atts['tipo'] : 'monumentos';
	$c = function_exists( 'mv3_get_counts' ) ? (array) mv3_get_counts() : array();
	$map = array( 'monumentos' => array( 'monuments', 'mv3_monument' ), 'artistas' => array( 'artists', 'mv3_artist' ), 'pontos' => array( 'access_points', 'mv3_access_point' ) );
	if ( ! isset( $map[ $t ] ) ) { return ''; }
	$n = isset( $c[ $map[ $t ][0] ] ) ? (int) $c[ $map[ $t ][0] ] : (int) wp_count_posts( $map[ $t ][1] )->publish;
	return (string) $n;
} );
