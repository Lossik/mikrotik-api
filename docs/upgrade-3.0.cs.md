# Přechod z 2.x na 3.0 (`lossik/mikrotik-api`, `lossik/mikrotik-ssh`)

Průvodce pro kód, který dnes používá `lossik/mikrotik-api` 2.x nebo `lossik/mikrotik-ssh` 2.x (a pod nimi
`lossik/device-communication` 2.x). Řada 3.0 je přepis: žádná třída 2.x nezůstala, takže zapomenuté místo skončí
chybou „Class not found“, ne tichou změnou chování. Úplný seznam změn je v `CHANGELOG.md` obou balíků, popis 3.0
v jejich `README.md`.

**3.0 zatím nevyšla**; instalační pokyny přijdou s vydáním.

## 1. Předpoklady

- **PHP** `^8.2`; podporované jsou jen opravené verze **8.2.35+, 8.3.36+, 8.4.27+ a 8.5.12+** (CVE-2026-109618
  a CVE-2026-109619, „PHP2Shell“). Composer opravené a neopravené sestavení nerozliší, minima hlídá jen CI těchto
  balíků.
- **Balíky**: oba stojí na `lossik/device-communication` `^3.0`. `lossik/mikrotik-ssh` 3.0 potřebuje
  **phpseclib 3** (`^3.0.57`, oprava CVE-2026-84308) místo phpseclib 2. Composer drží v projektu jedinou verzi
  `phpseclib/phpseclib`: **projekt, který má `phpseclib/phpseclib ^2` i jinde, nenainstaluje `mikrotik-ssh` 3.0,
  dokud ten kód nepřevede na phpseclib 3.**
- **Rozšíření**: `mikrotik-api` vyžaduje `ext-openssl` (ve 2.x jen doporučené) a `ext-iconv`; `mikrotik-ssh`
  vyžaduje `ext-iconv` (deklarované v `composer.json`; používá ho jen při jiném `charset` než UTF-8).
- **RouterOS** (podrobná tabulka „testováno při každém běhu CI / týdně / jen ručně / neověřeno“ je v README obou
  balíků):
  - **API**: podporovaná je celá řada 7.x a 6.x od 6.34 (první obraz CHR); živá sada je testuje na hraničních
    a zlomových verzích. Routery před 6.43 se přihlásí **automaticky** přes MD5 challenge, žádná volba to
    nepřepíná (živá sada na 6.34 a 6.42.12, ručně na 5.26 a 6.33; 6.43 přijme oba loginy, sada na ní projde už
    prvním). První věta loginu nese heslo čitelně i ke starému routeru, který ho ignoruje: na
    nedůvěryhodné síti použijte TLS. 5.x jen přes API, ověřeno ručně (5.26).
  - **SSH**: 3.0 funguje **od 6.34 výš**, ne až od 7.13: od 7.17 čte konzoli přes `:serialize to=json`, pod 7.17
    (a u routeru, jehož verzi nejde přečíst) jinou, pomalejší cestou přes serializér v jazyce konzole (viz README
    `mikrotik-ssh`, „Two console paths“). **5.x přes SSH podporované není**: konzole 5.x nehlásí chybu zápisu, takže
    selhání nejde odlišit od úspěchu. Taková zařízení zůstanou na `mikrotik-ssh` 2.x, nebo přejdou na API.
  - Ostatní verze jdou toutéž cestou bez záruky; nic se podle verze neodmítá, co router neumí, skončí výjimkou.

## 2. Co čím nahradit

### Jmenné prostory

| 2.x | 3.0 |
|---|---|
| `Lossik\Device\Communication\` (`device-communication`) | `Lossik\DeviceCommunication\` |
| `Lossik\Device\Mikrotik\Api\` | `Lossik\Mikrotik\Api\` |
| `Lossik\Device\Mikrotik\SSH\`, `Lossik\Device\Mikrotik\SFTP\` | `Lossik\Mikrotik\Ssh\` |

### Spojení (API)

| 2.x | 3.0 |
|---|---|
| `$o = new Options(); $o->port = …; $o->ssl = true; $o->sslPort = …; $o->timeout = …;` | `new ApiOptions(port: …, connectTimeout: …, idleTimeout: …, responseTimeout: …, cancelTimeout: …, charset: …, tls: …)` — vše pojmenované a volitelné. `port: null` = 8728, s `tls` 8729 (`sslPort` zmizel) |
| `$o->ssl = true` (vždy `ADH:ALL`, nic se neověřuje) | `tls: TlsOptions::verified(…)` (řetězec a jméno), `TlsOptions::fingerprint($sha256)` (self-signed certifikát routeru) nebo výslovně `TlsOptions::unverified()` — šifruje, ale router neověří, **nebezpečné** |
| `$o->timeout = 3` (connect i čtení) | `connectTimeout` (výchozí 10 s), `idleTimeout` (60 s ticha během odpovědi), `responseTimeout` (celá odpověď, výchozí bez limitu), `cancelTimeout` (10 s) |
| `$o->remoteCharset = 'WINDOWS-1250'` (výchozí), `$o->localCharset` | `charset: 'windows-1250'`; **výchozí je `'UTF-8'` bez převodu**, `localCharset` není (kód je vždy UTF-8) |
| `$c = new Connection($o); $c->connect($ip, $login, $password);` | `$transport = ApiTransport::connect($host, $user, $password, $options); $client = new Client($transport);` |
| `connect()` s poli loginů a hesel (zkouší každou dvojici) | není; zkoušejte v cyklu a chytejte `AuthenticationException` (každý pokus je nové spojení) |
| `isConnected()`, `isLoged()`, výjimka `RuntimeException` s kódem 2 („Login impossible“) | `connect()` buď vrátí přihlášený transport, nebo hodí výjimku (`AuthenticationException`, `ConnectionException`, `TimeoutException`); stav potom `$transport->isOpen()` |
| `disconnect()` | `$transport->close()` |
| `comm('/ip/address/print', ['?k' => 'v'])` | `$client->menu('/ip/address')->print(Query::where('k', 'v'))`; libovolné sloveso `->run($verb, $attributes)` |

### Spojení (SSH a SFTP)

| 2.x | 3.0 |
|---|---|
| `$o = new SSH\Options(); $o->port = 2222;` | `new SshOptions(port: 2222, connectTimeout: …, responseTimeout: …, charset: …)` |
| `$c = new SSH\Connection($o); $c->connect($ip, $login, $password);` | `$transport = SshTransport::connect($host, $user, $password, $options); $client = new Client($transport);` |
| prázdné heslo → přihlášení metodou `none` | heslo se posílá vždy, i prázdné. phpseclib ale `none` zkouší sám jako první: **účet s prázdným heslem se přihlásí s jakýmkoli heslem** |
| `comm(':put …')` (libovolný řádek konzole, vrací výstup) | není: transport spouští jen příkazy menu (`Command`) |
| `$s = new SFTP\Connection($o); $s->connect(…); $s->uploadfile($local, $remote); $s->downloadfile($remote, $local = false)` | `$files = FileTransfer::connect($host, $user, $password, $options); $files->upload($local, $remote); $files->download($remote, ?string $local = null)` — vrací obsah (`string`), nebo `true` po zápisu do `$local`; odmítnutí routerem je `TrapException` |

### Příkazy (API i SSH)

| 2.x | 3.0 |
|---|---|
| `$cmd = $connection->Command('/ip/address')` (SSH i `'ip address'`) | `$menu = $client->menu('/ip/address')`; obě podoby (`/ip/address`, `ip address`) platí pro oba transporty |
| typy `IConnection`, `ICommand` | `Transport` (implementace `ApiTransport`, `SshTransport`), `Client`, `Menu` |
| `get(['k' => 'v', 'k2' => 'v2'])` | `print(Query::and(Query::where('k', 'v'), Query::where('k2', 'v2')))`; jedna podmínka `Query::where('k', 'v')`; další: `has`, `hasNot`, `less`, `greater`, `or`, `not`. Druhý parametr `proplist: [...]` omezí sloupce |
| `get($where, $filterCallback)` | `array_filter($menu->print($query), $filterCallback)` |
| `get($where, null, true)` | `first($query)` (vrací řádek, nebo `null`) |
| hodnota s prefixem `~` (API: filtr podle začátku na klientovi; SSH: regulární výraz konzole) | podmínka neexistuje: `array_filter($rows, fn (array $r): bool => str_starts_with($r['name'] ?? '', 'abc'))`, pro regulární výraz `preg_match()` |
| `add($record)` | `add($attributes)` vrací ID nové položky (`?string`, např. `'*1B'`) |
| `update($where, $record, $filterCallback, $onlyFirstItem)` | `update(Query $query, $attributes, onlyFirst: …)` vrací počet změněných položek. Dotaz je povinný; pro všechny položky nebo s filtrem v PHP: `$ids = array_column(array_filter($menu->print(proplist: ['.id']), …), '.id')` a `run('set', ['.id' => implode(',', $ids)] + $attributes)` (jen když `$ids` není prázdné) |
| `del($where, $filterCallback, $onlyFirstItem)` | `delete(Query $query, onlyFirst: …)`, vrací počet; s filtrem v PHP jako u `update` přes `run('remove', ['.id' => …])` |
| `command('set', ['.id' => '*1', …])`, `command('enable', …)`, magické `$cmd->disable(…)` | `run('set', ['.id' => '*1', …])`, `run('enable', …)`, `run('disable', …)` |
| zastaralé `addItem`, `getItem($id)`, `getItems`, `getOneItem`, `updateItem($id, $record)`, `updateItems`, `updateOneItem`, `delItem($id)`, `delItems`, `delOneItem` (API) | `add`, `first(Query::where('.id', $id))`, `print`, `first`, `run('set', ['.id' => $id] + $record)`, `update`, `update(…, onlyFirst: true)`, `run('remove', ['.id' => $id])`, `delete`, `delete(…, onlyFirst: true)` |
| `$connection->version()`, `$cmd->getVersion()` | `$client->version()` (`?string`, např. `'7.24.5 (stable)'`; přes SSH taky — ve 2.x SSH hodilo `LogicException`) |
| `FormatHelper`, `Definition::arrayChangeKeyName()`, `Definition::isIterable()` | odstraněno, bez náhrady |
| `Lossik\Device\Communication\RuntimeException` (kódy) | rozhraní `Lossik\DeviceCommunication\CommunicationException` a jeho třídy, viz bod 3 (B5) |

Příklad:

```php
// 2.x
$connection = new \Lossik\Device\Mikrotik\Api\Connection();
$connection->connect('192.0.2.1', 'admin', 'secret');
$item = $connection->Command('/ppp/secret')->getOneItem(['name' => 'u1']);   // [] když nic

// 3.0
use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\Query;
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;

$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret', new ApiOptions(charset: 'windows-1250'));
$client = new Client($transport);
$item = $client->menu('/ppp/secret')->first(Query::where('name', 'u1'));     // null když nic
```

(`charset: 'windows-1250'` jen tam, kde data zapisovala 2.x s výchozím kódováním, viz B8.)

## 3. Co se změní v datech a chování

| # | 2.x | 3.0 |
|---|---|---|
| B1 | `=comment=a=b=c` se přečte jako `a` | `a=b=c` |
| B2 | hodnota s novým řádkem se rozdělí na víc slov, okrajové mezery se oříznou | hodnota projde celá |
| B3 | ticho routeru nebo zavřené spojení = čtení visí navždy (při EOF na plný výkon CPU); connect 3 s | `TimeoutException` po `idleTimeout` (60 s ticha), EOF hned `ConnectionException`; transport se zavře. Connect 10 s, `cancelTimeout` 10 s. **Pomalá odpověď**, která průběžně posílá data, projde jako ve 2.x (bez `responseTimeout` ji nic neutne). Dlouhé tiché příkazy (`/system/backup/save`, velký `/tool/fetch`) mohou 60 s ticha překročit: zvyšte jim `idleTimeout` |
| B4 | po `!fatal` spojení dál hlásí „connected“; příkaz po `disconnect()` → `TypeError` | `FatalException`, pak každé volání `ConnectionException` |
| B5 | jedna `RuntimeException` s číselnými kódy | `ConnectionException` (s podtřídami `TimeoutException` s `->limit`, `FatalException`, `ProtocolException`) zavírá transport; `AuthenticationException` jen z `connect()`; `TrapException` (`->category`, `->traps()`: všechny trapy odpovědi) nechá spojení otevřené |
| B6 | `update`/`del` bez shody pošle `.id=` (prázdné) | nepošle nic, vrátí 0 |
| B7 | prefix `~` (API: filtr na klientovi, SSH: regulární výraz) | neexistuje, filtr v PHP (bod 2) |
| B8 | výchozí charset `WINDOWS-1250`, nepřevoditelný znak tiše zahozen (`//IGNORE//TRANSLIT`) | výchozí **UTF-8 bez převodu**. Data, která 2.x zapsala s výchozím nastavením, jsou na routeru bajty cp1250: čtěte je (a zapisujte, chcete-li kódování zachovat) s `charset: 'windows-1250'`, jinak dostanete jiné bajty. Nepřevoditelný znak je chyba: v příkazu `\InvalidArgumentException` dřív, než se cokoli pošle, z routeru `ProtocolException` |
| B9 | TLS vždy `ADH:ALL`, certifikát se neověřuje; router bez certifikátu se s OpenSSL 1.1.0+ ani nespojí | ověřené TLS (`verified()`, `fingerprint()`); bez ověření jen výslovně `unverified()` |
| B10 | MD5 login (RouterOS před 6.43) | **funguje dál, automaticky** (nejdřív login 6.43+, na výzvu starého routeru MD5 odpověď na tomtéž spojení); heslo jde v první větě čitelně → TLS |
| B11 | `getOneItem()` → `[]` bez shody; zastaralé metody | `first()` → `null`; zastaralé metody zmizely |
| B12 | `add` vrátí `*N`, jen když `=ret=` přišlo s `!done` v jednom TCP segmentu, jinak `[]` | `?string` ID vždy, `null` když router žádné nepošle |
| B13 | SSH: chyba příkazu = úspěch (výstup jako výsledek); `$onlyFirstItem` ignorován; `;` a `=` v hodnotě rozbijí výsledek | `TrapException` s textem konzole; `onlyFirst` platí; hodnoty projdou celé (délkově označené nebo JSON) |
| B14 | `FormatHelper`, `arrayChangeKeyName`, `isIterable`, magické `__call` | odstraněno |

Navíc u SSH:

- **Řádky jsou jako z API**: hodnoty jsou řetězce, seznam spojený čárkou, prázdný `comment` chybí, ID z `add()`
  velkými písmeny (`*1B`). Pořadí klíčů není jako v API, nespoléhejte na něj. Zůstávají rozdíly vyjmenované
  v README `mikrotik-ssh` („Rows compared with the API“): jednotky `/queue/tree` a časů `/ip/dns`, čítače,
  `password` u `/ppp/secret`.
- **RouterOS 6.x přes SSH nevrací některé atributy**, které vrací API, ani když je vyjmenujete v `proplist` (konzole
  6.x `proplist` nemá) — mezi nimi i hodnoty zapsané uživatelem, např. `address-lists` u DHCP lease nebo
  `limit-bytes-in`/`limit-bytes-out` u `/ppp/secret`. Úplný změřený seznam je v README `mikrotik-ssh`
  („RouterOS 6.x: attributes the console does not print“). Na 6.x je čtěte přes API.
- **`set` s více ID není atomický** (stejně jako přes API): položky před prvním neznámým ID se změní, pak přijde
  `TrapException` (`no such item`).
- **Hodnoty se posílají uvozené a escapované** (`\`, `"`, `$`, `?`, konce řádků, každý bajt mimo tisknutelné ASCII
  jako `\HH`); 2.x dávalo do uvozovek jen hodnotu s mezerou a nic neescapovalo.
- **`less()`/`greater()`** přijmou jen nezáporné celé číslo nebo IPv4 adresu, jinak `UnsupportedOperationException`
  (konzole by porovnala jinak než API). Celé číslo se porovná stejně jako přes API; **IPv4 adresu porovná konzole
  jako adresu, API na RouterOS 6.42.12 … 7.25rc3 jako text** (`less('address', '10.97.3.10')` přes API najde
  `10.97.3.1`, ale ne `10.97.3.9`), takže SSH a API vrátí jiné řádky, kde se textové a adresní pořadí liší.
  `where(k, 'true'/'false')` vrací stejné řádky jako API. **`has(k)` přes SSH znamená neprázdnou hodnotu**
  a `hasNot(k)` chybějící nebo prázdnou; přes API je `has(k)` existence vlastnosti, i s prázdnou hodnotou: prázdnou
  `address` u `/ip/service` najde API v `has('address')`, SSH v `hasNot('address')`.
- **`responseTimeout` je 60 s** na jeden příkaz. Pod RouterOS 7.17 je čtení pomalejší (6–14 ms na řádek
  na emulovaném CHR): u velkých tabulek ho zvyšte.
- **Streamování** (`listen`) přes SSH není; je v `mikrotik-api`.

## 4. Jak migraci ověřit

- **Proti atrapě routeru**: `mikrotik-api` testuje proti falešnému serveru RouterOS API — `tests/Support/FakeRouter.php`,
  `FakeRouterServer.php` a `fake-router.php` (scénáře jako ticho, EOF uprostřed slova, rozdělené `!trap`/`!done`,
  `=ret=` v jiném segmentu, MD5 login). Je pod licencí MIT a jde zkopírovat do vlastních testů; v balíku z Composeru
  není (`tests/` se nebalí), vezměte ho z repozitáře. Na váš kód tak pustíte stejné scénáře, na kterých 2.x selhávala
  (bod 3).
- **Proti skutečnému routeru**: `tools/chr` (MIT, v obou repozitářích totožné) nabootuje MikroTik CHR v QEMU uvnitř
  jednoho kontejneru `php:8.2.35-cli`, jediný předpoklad je Docker (`tools/chr/README.md`; v balíku z Composeru
  také není, je jen v repozitáři). Vhodné na porovnání:
  stejný scénář přes 2.x a 3.0 proti témuž CHR a porovnat řádky — hlavně u dat s `=`, novými řádky a češtinou (B1,
  B2, B8).
- **Zkontrolujte kódování** dat zapsaných 2.x (B8): text s diakritikou přečtený bez `charset: 'windows-1250'` nebude
  platné UTF-8.
- **Projděte místa, která chytala `RuntimeException`** nebo kontrolovala `isConnected()`/`isLoged()`: ve 3.0 výjimka
  přijde jinde a jiného typu (B5), a transport po `ConnectionException` už nejde použít.
