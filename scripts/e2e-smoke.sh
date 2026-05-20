#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

APP_HOST="${APP_HOST:-127.0.0.1}"
APP_PORT="${APP_PORT:-8001}"
BASE_URL="${BASE_URL:-http://$APP_HOST:$APP_PORT}"
PHP_BIN="$ROOT_DIR/.local/bin/php-xboard"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php)"
RUN_DIR="$ROOT_DIR/.local/run"
FIXTURE_FILE="$RUN_DIR/e2e-fixture.json"
mkdir -p "$RUN_DIR"

log() { printf '[e2e-smoke] %s\n' "$*"; }
fail() { printf '[e2e-smoke] ERROR: %s\n' "$*" >&2; exit 1; }

php_bootstrap='require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'

cleanup() {
  "$PHP_BIN" <<'PHP' >/dev/null 2>&1 || true
<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$userIds = User::where('email', 'like', 'xboard-e2e-%@example.invalid')->pluck('id')->all();
if ($userIds) {
    DB::table('personal_access_tokens')
        ->where('tokenable_type', User::class)
        ->whereIn('tokenable_id', $userIds)
        ->delete();
    User::whereIn('id', $userIds)->delete();
}
Server::where('code', 'like', 'xboard-e2e-%')->delete();
Plan::where('name', 'like', '[e2e]%')->delete();
ServerGroup::where('name', 'like', '[e2e]%')->delete();
foreach (['xboard-e2e-forget@example.invalid', 'xboard-e2e-mail@example.invalid'] as $email) {
    Cache::forget('EMAIL_VERIFY_CODE_' . $email);
    Cache::forget('LAST_SEND_EMAIL_VERIFY_TIMESTAMP_' . $email);
    Cache::forget('FORGET_REQUEST_LIMIT_' . $email);
}
PHP
}
trap cleanup EXIT

setup_fixture() {
  cleanup
  "$PHP_BIN" <<'PHP'
<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Support\Str;

$stamp = date('YmdHis');
$now = time();
$password = 'E2eSmokePass123!';
$securePath = ltrim((string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), '/');
$subscribePath = ltrim((string) admin_setting('subscribe_path', 's'), '/');
$telegramAccessToken = md5((string) admin_setting('telegram_bot_token'));

$group = new ServerGroup();
$group->name = '[e2e] smoke group ' . $stamp;
$group->save();

$plan = Plan::create([
    'group_id' => $group->id,
    'transfer_enable' => 1024 * 1024 * 1024,
    'name' => '[e2e] smoke plan ' . $stamp,
    'show' => true,
    'sort' => 1,
    'renew' => true,
    'sell' => true,
    'prices' => ['monthly' => 100],
]);

$member = User::create([
    'email' => 'xboard-e2e-member-' . $stamp . '@example.invalid',
    'password' => password_hash($password, PASSWORD_DEFAULT),
    'password_algo' => null,
    'password_salt' => null,
    'uuid' => (string) Str::uuid(),
    'token' => md5('member' . $stamp . random_int(1, PHP_INT_MAX)),
    'transfer_enable' => 1024 * 1024 * 1024,
    'u' => 0,
    'd' => 0,
    'expired_at' => $now + 86400,
    'banned' => 0,
    'is_admin' => 0,
    'group_id' => $group->id,
    'plan_id' => $plan->id,
    'remind_expire' => 1,
    'remind_traffic' => 1,
]);

$admin = User::create([
    'email' => 'xboard-e2e-admin-' . $stamp . '@example.invalid',
    'password' => password_hash($password, PASSWORD_DEFAULT),
    'password_algo' => null,
    'password_salt' => null,
    'uuid' => (string) Str::uuid(),
    'token' => md5('admin' . $stamp . random_int(1, PHP_INT_MAX)),
    'transfer_enable' => 0,
    'u' => 0,
    'd' => 0,
    'expired_at' => 0,
    'banned' => 0,
    'is_admin' => 1,
    'remind_expire' => 1,
    'remind_traffic' => 1,
]);

$server = Server::create([
    'type' => Server::TYPE_SHADOWSOCKS,
    'code' => 'xboard-e2e-' . $stamp,
    'group_ids' => [(string) $group->id],
    'route_ids' => [],
    'name' => '[e2e] SS Smoke',
    'rate' => 1,
    'tags' => ['e2e'],
    'host' => '198.51.100.10',
    'port' => '8388',
    'server_port' => 8388,
    'protocol_settings' => ['cipher' => 'aes-128-gcm'],
    'show' => true,
    'sort' => 1,
    'transfer_enable' => 0,
    'u' => 0,
    'd' => 0,
    'enabled' => true,
]);

echo json_encode([
    'secure_path' => $securePath,
    'subscribe_path' => $subscribePath ?: 's',
    'telegram_access_token' => $telegramAccessToken,
    'password' => $password,
    'admin_email' => $admin->email,
    'member_email' => $member->email,
    'member_token' => $member->token,
    'register_email' => 'xboard-e2e-register-' . $stamp . '@example.invalid',
    'mail_email' => 'xboard-e2e-mail-' . $stamp . '@example.invalid',
    'forget_email' => $member->email,
    'forget_code' => '123456',
    'server_id' => $server->id,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP
}

json_get() {
  python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$FIXTURE_FILE" "$1"
}

http_code() {
  curl -sS --max-time 8 -o /tmp/e2e-body.$$ -w '%{http_code}' "$@"
}

assert_code() {
  local expected="$1" actual="$2" label="$3"
  [ "$actual" = "$expected" ] || { cat /tmp/e2e-body.$$ >&2 2>/dev/null || true; fail "$label expected HTTP $expected, got $actual"; }
  log "OK: $label -> HTTP $actual"
}

assert_json_path() {
  local file="$1" path="$2" expected="$3" label="$4"
  python3 - "$file" "$path" "$expected" <<'PY' || fail "$label expected $path == $expected"
import json, sys
file, path, expected = sys.argv[1:4]
data = json.load(open(file))
cur = data
for part in path.split('.'):
    cur = cur[part]
if str(cur).lower() != expected.lower():
    raise SystemExit(f'{cur!r} != {expected!r}')
PY
}

extract_json_path() {
  local file="$1" path="$2"
  python3 - "$file" "$path" <<'PY'
import json, sys
cur = json.load(open(sys.argv[1]))
for part in sys.argv[2].split('.'):
    cur = cur[part]
print(cur)
PY
}

post_json() {
  local url="$1" data="$2" auth="${3:-}"
  if [ -n "$auth" ]; then
    http_code -X POST -H 'Content-Type: application/json' -H "Authorization: $auth" --data "$data" "$url"
  else
    http_code -X POST -H 'Content-Type: application/json' --data "$data" "$url"
  fi
}

get_auth() {
  local url="$1" auth="$2"
  http_code -H "Authorization: $auth" "$url"
}

log "starting local runtime"
./scripts/dev-up.sh >/dev/null
setup_fixture > "$FIXTURE_FILE"

secure="$(json_get secure_path)"
subscribe_path="$(json_get subscribe_path)"
admin_email="$(json_get admin_email)"
member_email="$(json_get member_email)"
member_token="$(json_get member_token)"
password="$(json_get password)"
register_email="$(json_get register_email)"
mail_email="$(json_get mail_email)"
forget_code="$(json_get forget_code)"
telegram_access_token="$(json_get telegram_access_token)"

code="$(curl -IsS --max-time 8 "$BASE_URL/" | awk 'NR==1 {print $2}')"
[ "$code" = "404" ] || fail "public root expected 404, got ${code:-000}"
log "OK: public root / -> HTTP 404"

code="$(http_code "$BASE_URL/$secure")"
assert_code 200 "$code" "admin shell /$secure"

code="$(post_json "$BASE_URL/api/v2/$secure/auth/login" "{\"email\":\"$admin_email\",\"password\":\"$password\"}")"
assert_code 200 "$code" "admin auth/login"
assert_json_path /tmp/e2e-body.$$ status success "admin auth/login JSON status"
assert_json_path /tmp/e2e-body.$$ data.is_admin True "admin auth/login is_admin"
admin_auth="$(extract_json_path /tmp/e2e-body.$$ data.auth_data)"

code="$(get_auth "$BASE_URL/api/v2/$secure/auth/me" "$admin_auth")"
assert_code 200 "$code" "admin auth/me"
assert_json_path /tmp/e2e-body.$$ data.is_admin True "admin auth/me is_admin"

code="$(post_json "$BASE_URL/api/v2/$secure/auth/logout" '{}' "$admin_auth")"
assert_code 200 "$code" "admin auth/logout"
code="$(get_auth "$BASE_URL/api/v2/$secure/auth/me" "$admin_auth")"
assert_code 403 "$code" "admin auth/me after logout"

code="$(post_json "$BASE_URL/api/v1/passport/auth/login" "{\"email\":\"$member_email\",\"password\":\"$password\"}")"
assert_code 200 "$code" "V1 passport login"
member_auth="$(extract_json_path /tmp/e2e-body.$$ data.auth_data)"

code="$(get_auth "$BASE_URL/api/v1/user/info" "$member_auth")"
assert_code 200 "$code" "V1 user/info"
assert_json_path /tmp/e2e-body.$$ status success "V1 user/info JSON status"

code="$(get_auth "$BASE_URL/api/v2/user/info" "$member_auth")"
assert_code 200 "$code" "V2 user/info"
assert_json_path /tmp/e2e-body.$$ status success "V2 user/info JSON status"

code="$(post_json "$BASE_URL/api/v2/passport/auth/login" "{\"email\":\"$member_email\",\"password\":\"$password\"}")"
assert_code 200 "$code" "V2 passport login"

code="$(post_json "$BASE_URL/api/v1/passport/auth/register" "{\"email\":\"$register_email\",\"password\":\"$password\"}")"
assert_code 200 "$code" "V1 passport register"
assert_json_path /tmp/e2e-body.$$ status success "V1 passport register JSON status"

code="$(post_json "$BASE_URL/api/v1/passport/comm/sendEmailVerify" "{\"email\":\"$mail_email\"}")"
assert_code 200 "$code" "V1 sendEmailVerify"

"$PHP_BIN" -r "$php_bootstrap Illuminate\\Support\\Facades\\Cache::put(App\\Utils\\CacheKey::get('EMAIL_VERIFY_CODE', '$member_email'), '$forget_code', 300);"
code="$(post_json "$BASE_URL/api/v1/passport/auth/forget" "{\"email\":\"$member_email\",\"email_code\":\"$forget_code\",\"password\":\"$password\"}")"
assert_code 200 "$code" "V1 passport forget"
assert_json_path /tmp/e2e-body.$$ status success "V1 passport forget JSON status"

code="$(post_json "$BASE_URL/api/v1/passport/auth/getQuickLoginUrl" "{\"auth_data\":\"$member_auth\",\"redirect\":\"dashboard\"}")"
assert_code 200 "$code" "V1 getQuickLoginUrl"

code="$(curl -sS -o /tmp/e2e-body.$$ -w '%{http_code}' --max-time 8 "$BASE_URL/api/v1/passport/auth/token2Login?token=e2e-token&redirect=dashboard")"
assert_code 302 "$code" "V1 token2Login redirect"

code="$(http_code "$BASE_URL/api/v1/guest/comm/config")"
assert_code 200 "$code" "guest comm/config"
code="$(http_code "$BASE_URL/api/v1/guest/plan/fetch")"
assert_code 200 "$code" "guest plan/fetch"

code="$(curl -sS --max-time 8 -D /tmp/e2e-sub-headers.$$ -o /tmp/e2e-sub-body.$$ -w '%{http_code}' "$BASE_URL/$subscribe_path/$member_token?flag=v2rayn")"
[ "$code" = "200" ] || { cat /tmp/e2e-sub-body.$$ >&2; fail "subscribe expected HTTP 200, got $code"; }
python3 - /tmp/e2e-sub-body.$$ <<'PY' || fail "subscribe body did not decode to an ss:// node"
import base64, sys
raw = open(sys.argv[1], 'rb').read().strip()
text = base64.b64decode(raw + b'=' * (-len(raw) % 4)).decode('utf-8', 'replace')
if 'ss://' not in text or 'SS%20Smoke' not in text:
    raise SystemExit(text)
PY
grep -qi '^subscription-userinfo:' /tmp/e2e-sub-headers.$$ || fail 'subscribe response missing subscription-userinfo header'
log "OK: subscribe /$subscribe_path/{token} -> HTTP 200 with ss:// payload"

code="$(curl -sS --max-time 8 -o /tmp/e2e-body.$$ -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data '{}' "$BASE_URL/api/v1/guest/telegram/webhook?access_token=$telegram_access_token")"
assert_code 200 "$code" "telegram webhook empty update"

payment_code="$(curl -sS --max-time 8 -o /tmp/e2e-body.$$ -w '%{http_code}' "$BASE_URL/api/v1/guest/payment/notify/e2e/missing-uuid")"
[ "$payment_code" != "404" ] && [ "$payment_code" != "405" ] || fail "payment notify route not reachable, got HTTP $payment_code"
log "OK: payment notify route reached controller boundary -> HTTP $payment_code (expected failure without provider fixture)"

log "E2E smoke passed"
