<?php
/**
 * Envia um e-mail de teste (igual ao aviso "Monumento inscrito") para conferir se chega na caixa de entrada.
 * Uso: wp eval-file mapa-wifi/tools/teste-email.php            (manda para monumentosvirtuais@gmail.com)
 *      wp eval-file mapa-wifi/tools/teste-email.php outro@x.com
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$para = isset( $args[0] ) && is_email( $args[0] ) ? $args[0] : ( defined( 'MVE_PARA' ) ? MVE_PARA : 'monumentosvirtuais@gmail.com' );
$dom  = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$erro = '';
add_action( 'wp_mail_failed', function ( $e ) use ( &$erro ) { $erro = $e->get_error_message(); } );
$html = '<div style="font-family:Arial,sans-serif;background:#f2ede6;padding:24px"><div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e3ddd4">'
	. '<div style="background:#0A0A0A;padding:18px 24px;color:#F2EDE6;font-size:20px;font-weight:700">Monumento inscrito <span style="color:#C67033">(teste)</span></div>'
	. '<div style="padding:22px 24px;font-size:14px;line-height:1.6;color:#141210">Se este e-mail chegou na <b>caixa de entrada</b>, os avisos do Mapa Wi-Fi v2.0 vão chegar também.<br>'
	. 'Se caiu no <b>spam</b>, marque "Não é spam" e avise: dá para configurar o envio pela conta de e-mail do domínio.</div></div></div>';
$ok = wp_mail( $para, 'Monumento inscrito: teste de envio', $html, array( 'Content-Type: text/html; charset=UTF-8', 'From: Monumentos Virtuais <nao-responda@' . $dom . '>' ) );
if ( $ok ) {
	WP_CLI::success( "E-mail de teste enviado para $para (remetente nao-responda@$dom). Confira a caixa de entrada e o spam em alguns minutos." );
} else {
	WP_CLI::error( "O servidor não conseguiu enviar para $para. Motivo: " . ( $erro ? $erro : 'não informado' ) );
}
