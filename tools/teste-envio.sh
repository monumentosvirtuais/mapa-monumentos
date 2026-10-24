#!/bin/bash
# Repete um envio do formulário "Erguer monumento" a partir do servidor, para achar onde ele trava.
#   1) caminho normal, pelo endereço público (passa pelo CDN da Hostinger, como o celular)
#   2) caminho normal, direto no servidor (sem CDN)
#   3) plano B (admin-ajax), pelo endereço público
# Tudo o que o teste cria é apagado no fim. Uso, na pasta public_html:
#   bash mapa-wifi/tools/teste-envio.sh
cd "$(dirname "$0")/../.." || exit 1
SITE="https://monumentosvirtuais.ong.br"
FOTO=$(ls -S wp-content/uploads/20*/*/*.jpg 2>/dev/null | grep -v -- '-[0-9]*x[0-9]*\.jpg' | tail -1)
[ -z "$FOTO" ] && { echo "Nenhuma foto .jpg encontrada em wp-content/uploads para o teste."; exit 1; }
echo "Foto usada no teste: $FOTO ($(du -h "$FOTO" | cut -f1))"
IP=$(dig +short monumentosvirtuais.ong.br A 2>/dev/null | tail -1)
echo "O endereço público aponta para: ${IP:-?}"

envia () { # $1 = rótulo, $2 = endereço, $3.. = opções extras do curl
  local rot="$1" url="$2"; shift 2
  local uuid; uuid=$(cat /proc/sys/kernel/random/uuid)
  local out; out=$(curl -s -m 90 -w '\n__STATUS__%{http_code}' "$@" -X POST "$url" -F "envio_id=$uuid" \
    -F "mName=TESTE automático - pode apagar" -F "mYear=2026" -F "mBairro=Teste, Recife, PE" \
    -F "mHist=Envio de teste feito pelo servidor para conferir o formulário. Pode apagar." \
    -F "lat=-8.05" -F "lng=-34.9" -F "raio=300" -F 'ficha=[{"role":"Direção","people":["Teste"]}]' \
    -F "aNome=Perfil de teste" -F "aBio=Perfil criado automaticamente para testar o envio. Pode apagar." \
    -F "aEmail=monumentosvirtuais@gmail.com" -F "fotos[]=@$FOTO;type=image/jpeg" -F "aFoto=@$FOTO;type=image/jpeg")
  local st="${out##*__STATUS__}"; local corpo="${out%__STATUS__*}"
  echo; echo "== $rot: HTTP $st"
  echo "$corpo" | head -c 400; echo
  # apaga o que o teste criou: monumento, perfil, fotos e a inscrição guardada
  local rid; rid=$(wp post list --post_type=mve_inscricao --post_status=any --meta_key=_mve_uuid --meta_value="$uuid" --format=ids 2>/dev/null)
  local id; id=$(echo "$corpo" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
  [ -z "$id" ] && [ -n "$rid" ] && id=$(wp post meta get "$rid" _mve_monumento 2>/dev/null)
  if [ -n "$id" ]; then
    local art; art=$(wp post meta get "$id" _mv3_artist_id 2>/dev/null)
    for a in $(wp post list --post_type=attachment --post_parent="$id" --format=ids 2>/dev/null) $(wp post list --post_type=attachment --post_parent="$art" --format=ids 2>/dev/null); do wp post delete "$a" --force >/dev/null 2>&1; done
    wp post delete "$id" --force >/dev/null 2>&1
    [ -n "$art" ] && [ "$(wp post meta get "$art" _mve_comunidade 2>/dev/null)" = "1" ] && [ "$(wp post meta get "$art" _mve_email 2>/dev/null)" = "monumentosvirtuais@gmail.com" ] && wp post delete "$art" --force >/dev/null 2>&1
    echo "(monumento de teste $id apagado)"
  fi
  if [ -n "$rid" ]; then
    echo "(inscrição guardada: $(wp post meta get "$rid" _mve_estado 2>/dev/null); apagada)"
    rm -rf "wp-content/uploads/mv-erguer-inscricoes/$uuid"; wp post delete "$rid" --force >/dev/null 2>&1
  fi
}

envia "1) caminho normal, pelo endereço público (com CDN)" "$SITE/wp-json/mv-erguer/v1/enviar"
envia "2) caminho normal, direto no servidor (sem CDN)" "$SITE/wp-json/mv-erguer/v1/enviar" --resolve "monumentosvirtuais.ong.br:443:127.0.0.1" -k
envia "3) plano B, pelo endereço público (com CDN)" "$SITE/wp-admin/admin-ajax.php?action=mve_guardar" -F "modo=completo"

echo; echo "== Últimos registros do envio:"
tail -n 8 wp-content/uploads/mv-erguer-log/erros.log 2>/dev/null || echo "(nenhum registro ainda)"
echo; echo "== Limites do PHP:"
php -r 'foreach(["upload_max_filesize","post_max_size","max_file_uploads","memory_limit","max_execution_time"] as $k) echo "$k=".ini_get($k)."  "; echo "\n";'
wp eval 'echo "PHP do site: upload_max_filesize=".ini_get("upload_max_filesize")." post_max_size=".ini_get("post_max_size")." memory_limit=".ini_get("memory_limit")."\n";' 2>/dev/null
