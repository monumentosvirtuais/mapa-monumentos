<?php
/**
 * Revisar monumentos enviados pelo mapa. Rode na pasta public_html:
 *   wp eval-file mapa-wifi/tools/revisar.php ver 475 471 460      → mostra o que cada um tem
 *   wp eval-file mapa-wifi/tools/revisar.php apagar 471            → apaga monumento repetido (e as fotos dele; o perfil fica)
 *   wp eval-file mapa-wifi/tools/revisar.php avisar 475            → manda de novo o e-mail de aviso para a equipe
 * Só mexe em monumentos que vieram do formulário do mapa.
 */
$args = isset( $args ) ? $args : array();
$acao = array_shift( $args );
$ids  = array_filter( array_map( 'intval', $args ) );
if ( ! in_array( $acao, array( 'ver', 'apagar', 'avisar' ), true ) || ! $ids ) {
	WP_CLI::error( 'Use: ver|apagar|avisar seguido dos números (ID) dos monumentos.' );
}
foreach ( $ids as $id ) {
	$p = get_post( $id );
	if ( ! $p || 'mv3_monument' !== $p->post_type ) { WP_CLI::warning( "$id não é um monumento." ); continue; }
	if ( '1' !== get_post_meta( $id, '_mve_comunidade', true ) ) { WP_CLI::warning( "$id não veio do formulário do mapa; não mexo." ); continue; }
	$art   = (int) get_post_meta( $id, '_mv3_artist_id', true );
	$fotos = array_filter( array_map( 'intval', (array) get_post_meta( $id, '_mve_fotos', true ) ) );
	if ( 'ver' === $acao ) {
		$ficha = trim( (string) get_post_meta( $id, '_mv3_ficha', true ) );
		$hist  = wp_strip_all_tags( $p->post_content );
		WP_CLI::log( str_repeat( '-', 60 ) );
		WP_CLI::log( "#$id  {$p->post_title}  [" . $p->post_status . "]  " . $p->post_date );
		WP_CLI::log( '  artista: ' . ( $art ? "#$art " . get_the_title( $art ) . ' [' . get_post_status( $art ) . ']' : '—' ) );
		WP_CLI::log( '  e-mail do envio: ' . get_post_meta( $id, '_mve_email_envio', true ) );
		WP_CLI::log( '  fotos: ' . count( $fotos ) . ' · vídeo: ' . ( get_post_meta( $id, '_mv3_ytid', true ) ?: '—' ) . ' · drive: ' . ( get_post_meta( $id, '_mve_drive', true ) ?: '—' ) );
		WP_CLI::log( '  ano: ' . get_post_meta( $id, '_mv3_year', true ) . ' · lugar: ' . get_post_meta( $id, '_mv3_lat', true ) . ', ' . get_post_meta( $id, '_mv3_lng', true ) );
		WP_CLI::log( '  história: ' . mb_substr( preg_replace( '/\s+/', ' ', $hist ), 0, 110 ) . ( mb_strlen( $hist ) > 110 ? '…' : '' ) . ' (' . mb_strlen( $hist ) . ' letras)' );
		WP_CLI::log( '  ficha: ' . ( $ficha ? str_replace( "\n", ' | ', $ficha ) : '—' ) );
		$sit = array();
		if ( get_post_meta( $id, '_mve_perfil_existente', true ) ) { $sit[] = 'escolheu perfil já existente'; }
		if ( get_post_meta( $id, '_mve_aguarda_vinculo', true ) ) { $sit[] = 'aguarda confirmar o perfil'; }
		$sit[] = 'e-mail de aviso: ' . ( '1' === get_post_meta( $id, '_mve_email_enviado', true ) ? 'enviado' : 'NÃO enviado' );
		WP_CLI::log( '  ' . implode( ' · ', $sit ) );
	} elseif ( 'apagar' === $acao ) {
		foreach ( array_unique( array_merge( $fotos, array_keys( get_attached_media( '', $id ) ) ) ) as $a ) {
			if ( (int) get_post_thumbnail_id( $art ) === $a ) { continue; } // nunca a foto do perfil
			wp_delete_attachment( $a, true );
		}
		wp_delete_post( $id, true );
		WP_CLI::success( "#$id “{$p->post_title}” apagado (o perfil de artista continua)." );
	} else {
		if ( get_post_meta( $id, '_mve_aguarda_vinculo', true ) ) { $modo = 'confirmar'; }
		elseif ( get_post_meta( $id, '_mve_perfil_existente', true ) ) { $modo = 'existente'; }
		else { $modo = ( $art && abs( strtotime( get_post_field( 'post_date_gmt', $art ) ) - strtotime( $p->post_date_gmt ) ) < 120 ) ? 'novo' : 'mesmo_email'; }
		$ok = mve_email_inscrito( $id, $modo );
		update_post_meta( $id, '_mve_email_enviado', $ok ? '1' : '0' );
		$ok ? WP_CLI::success( "Aviso de “{$p->post_title}” enviado para a equipe ($modo)." ) : WP_CLI::warning( "O e-mail de “{$p->post_title}” não saiu." );
	}
}
