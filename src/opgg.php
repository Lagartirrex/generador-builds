<?php
/*
  ============================================================
  OP.GG (servidor MCP: JSON-RPC 2.0 sobre HTTP)
  ============================================================
  OP.GG NO devuelve JSON en el bloque de texto del MCP: devuelve un
  formato propio de "clases con argumentos posicionales" (tipo
  toString() de Kotlin), con su esquema declarado arriba del payload
  ("class X: campo1,campo2,..."). Se parsea con OpggExprParser.

  Datos confirmados con respuestas reales:
   - win_rate/pick_rate/ban_rate vienen como fraccion (0.51).
   - Las runas vienen con nombre; Data Dragon solo aporta el icono.
   - Los fragmentos de runas vienen como ids numericos (ver FRAGMENTOS).
   - Los items situacionales estan repartidos en fourth_items/
     fifth_items/sixth_items (alternativas por slot).
   - Los hechizos vienen como ids legacy (4=Destello, 12=Teletransporte)
     y se resuelven contra el campo "key" de summoner.json.
   - summary.average_stats promedia TODOS los roles; para un rol
     concreto se usa summary.positions[].stats.
*/

/*
  Separa las lineas "class X: campo1,campo2,..." (esquema) del resto
  (la expresion de datos real), arma el mapa de esquemas, y parsea
  la expresion con OpggExprParser. Devuelve null si el texto no
  tiene este formato o si el parseo falla.
 */
function parseOpggClassFormat(string $text): ?array
{
    $lines = explode("\n", $text);
    $schemas = [];
    $lastSchemaLine = -1;

    foreach ($lines as $idx => $line) {
        if (preg_match('/^class\s+(\w+):\s*(.*)$/', trim($line), $m)) {
            $schemas[$m[1]] = array_map('trim', explode(',', $m[2]));
            $lastSchemaLine = $idx;
        }
    }

    if ($lastSchemaLine === -1) {
        return null;
    }

    $exprText = trim(implode("\n", array_slice($lines, $lastSchemaLine + 1)));
    if ($exprText === '') {
        return null;
    }

    try {
        return (new OpggExprParser($exprText, $schemas))->parse();
    } catch (Throwable $ex) {
        error_log('[opgg-parser] ' . $ex->getMessage());
        return null;
    }
}

/*
  Parser recursivo de descenso para expresiones tipo
  "ClassName(arg1, arg2, [arr1, arr2], "string", 1.23, true)".
  Usa $schemas (className => [nombresDeCampo]) para convertir cada
  tupla posicional en un array asociativo con nombres reales,
  recursivamente en todo el arbol.
 */
class OpggExprParser
{
    private int $i = 0;
    private int $len;

    public function __construct(private string $s, private array $schemas)
    {
        $this->len = strlen($s);
    }

    public function parse()
    {
        return $this->parseValue();
    }

    private function skipWs(): void
    {
        while ($this->i < $this->len && strpos(" \t\r\n", $this->s[$this->i]) !== false) {
            $this->i++;
        }
    }

    private function peek(): string
    {
        return $this->i < $this->len ? $this->s[$this->i] : '';
    }

    private function parseValue()
    {
        $this->skipWs();
        $c = $this->peek();

        if ($c === '"') {
            return $this->parseString();
        }
        if ($c === '[') {
            return $this->parseArray();
        }
        if ($c !== '' && (ctype_digit($c) || $c === '-')) {
            return $this->parseNumber();
        }

        $ident = $this->parseIdent();
        if ($ident === '') {
            throw new RuntimeException('No pude parsear cerca de: ' . substr($this->s, $this->i, 60));
        }
        if ($ident === 'true') {
            return true;
        }
        if ($ident === 'false') {
            return false;
        }
        if ($ident === 'null') {
            return null;
        }

        $this->skipWs();
        if ($this->peek() === '(') {
            return $this->buildObject($ident, $this->parseArgList());
        }

        return $ident; // identificador suelto, no deberia pasar en este dataset
    }

    private function parseString(): string
    {
        $this->i++; // comilla de apertura
        $out = '';
        while ($this->i < $this->len && $this->s[$this->i] !== '"') {
            if ($this->s[$this->i] === '\\' && $this->i + 1 < $this->len) {
                $this->i++;
            }
            $out .= $this->s[$this->i];
            $this->i++;
        }
        $this->i++; // comilla de cierre
        return $out;
    }

    private function parseNumber()
    {
        $start = $this->i;
        if ($this->peek() === '-') {
            $this->i++;
        }
        while ($this->i < $this->len && (ctype_digit($this->s[$this->i]) || strpos('.eE+-', $this->s[$this->i]) !== false)) {
            $this->i++;
        }
        $numStr = substr($this->s, $start, $this->i - $start);
        return (strpos($numStr, '.') !== false || stripos($numStr, 'e') !== false) ? (float) $numStr : (int) $numStr;
    }

    private function parseIdent(): string
    {
        $start = $this->i;
        while ($this->i < $this->len && (ctype_alnum($this->s[$this->i]) || $this->s[$this->i] === '_')) {
            $this->i++;
        }
        return substr($this->s, $start, $this->i - $start);
    }

    private function parseArray(): array
    {
        $this->i++; // [
        $out = [];
        $this->skipWs();
        if ($this->peek() === ']') {
            $this->i++;
            return $out;
        }
        while (true) {
            $out[] = $this->parseValue();
            $this->skipWs();
            if ($this->peek() === ',') {
                $this->i++;
                continue;
            }
            break;
        }
        $this->skipWs();
        if ($this->peek() === ']') {
            $this->i++;
        }
        return $out;
    }

    private function parseArgList(): array
    {
        $this->i++; // (
        $out = [];
        $this->skipWs();
        if ($this->peek() === ')') {
            $this->i++;
            return $out;
        }
        while (true) {
            $out[] = $this->parseValue();
            $this->skipWs();
            if ($this->peek() === ',') {
                $this->i++;
                continue;
            }
            break;
        }
        $this->skipWs();
        if ($this->peek() === ')') {
            $this->i++;
        }
        return $out;
    }

    private function buildObject(string $className, array $args): array
    {
        $fields = $this->schemas[$className] ?? null;
        if (!$fields) {
            return ['_class' => $className, '_args' => $args];
        }
        $obj = ['_class' => $className];
        foreach ($fields as $idx => $name) {
            $obj[$name] = $args[$idx] ?? null;
        }
        return $obj;
    }
}

/*
  Cliente MCP. Reutiliza la MISMA conexion (un solo handle de curl)
  para initialize + notificacion + llamada, asi el handshake TLS se
  hace una sola vez por visita en vez de tres.
*/
function mcpCallTool(string $endpoint, string $tool, array $arguments, string $method = 'tools/call'): array
{
    static $sessionId = null;
    static $ch = null;

    $post = function (array $body) use ($endpoint, &$sessionId, &$ch): array {
        $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
        if ($sessionId) {
            $headers[] = 'Mcp-Session-Id: ' . $sessionId;
        }
        $ch ??= curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => HTTP_USER_AGENT,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Error de red hacia OP.GG MCP: ' . curl_error($ch));
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerText = substr((string) $raw, 0, $headerSize);
        $bodyText = substr((string) $raw, $headerSize);

        if ($httpCode !== 200 && $httpCode !== 202) {
            throw new RuntimeException("OP.GG MCP devolvió código HTTP {$httpCode}. Respuesta: " . substr(strip_tags($bodyText), 0, 300));
        }
        if (preg_match('/Mcp-Session-Id:\s*(.+)/i', $headerText, $m)) {
            $sessionId = trim($m[1]);
        }
        if ($httpCode === 202 && trim($bodyText) === '') {
            return [];
        }

        if (stripos($headerText, 'text/event-stream') !== false) {
            foreach (explode("\n", $bodyText) as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'data:')) {
                    $decoded = json_decode(trim(substr($line, 5)), true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
            throw new RuntimeException('No pude parsear la respuesta SSE de OP.GG MCP.');
        }

        $decoded = json_decode($bodyText, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta MCP inválida (no es JSON): ' . substr(trim($bodyText), 0, 300));
        }
        return $decoded;
    };

    if (!$sessionId) {
        $init = $post([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'mazterlag-build-generator', 'version' => '2.0.0'],
            ],
        ]);
        if (isset($init['error'])) {
            throw new RuntimeException('MCP initialize error: ' . ($init['error']['message'] ?? '?'));
        }
        $post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    }

    $resp = $post([
        'jsonrpc' => '2.0', 'id' => 2, 'method' => $method,
        'params' => $method === 'tools/call' ? ['name' => $tool, 'arguments' => $arguments] : new stdClass(),
    ]);
    if (isset($resp['error'])) {
        throw new RuntimeException("MCP {$method} error: " . ($resp['error']['message'] ?? '?'));
    }
    if ($method !== 'tools/call') {
        return $resp['result'] ?? [];
    }

    $result = $resp['result'] ?? [];
    foreach (($result['content'] ?? []) as $block) {
        if (($block['type'] ?? null) !== 'text') {
            continue;
        }
        $text = (string) ($block['text'] ?? '');
        $asJson = json_decode($text, true); // por si algun dia OP.GG cambia a JSON de verdad
        if (is_array($asJson)) {
            return $asJson;
        }
        $parsed = parseOpggClassFormat($text);
        if (is_array($parsed)) {
            return $parsed;
        }
    }
    return $result;
}

function esBuildValida($d): bool
{
    return is_array($d) && (isset($d['summary']) || isset($d['runes']) || isset($d['core_items']));
}

/*
  Devuelve el analisis de OP.GG para campeon+rol (o null). Cachea 6 h
  y deja SIEMPRE un volcado legible en cache/opgg-debug/ para revisar
  que llego realmente.
*/
function obtenerBuildOpgg(string $championId, string $position, array $extra = [], bool $forzar = false): ?array
{
    $slug = strtolower($championId) . '_' . strtolower($position);
    if ($extra) {
        ksort($extra);
        $slug .= '_' . substr(md5(json_encode($extra)), 0, 8); // cada combinacion de filtros tiene su propia cache
    }
    $cacheFile = rutaCache('opgg/' . $slug . '.json');
    $debugFile = rutaCache('opgg-debug/' . $slug . '.json');

    if (!$forzar) {
        $cached = leerJson($cacheFile, OPGG_CACHE_TTL);
        if (esBuildValida($cached)) {
            return $cached;
        }
    }

    $arguments = ['champion' => $championId, 'position' => $position, 'game_mode' => OPGG_GAME_MODE, 'lang' => OPGG_LANG] + $extra;
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    try {
        $parsed = mcpCallTool(OPGG_MCP_ENDPOINT, 'lol_get_champion_analysis', $arguments);
    } catch (Throwable $ex) {
        escribirAtomico($debugFile, json_encode(['timestamp' => date('c'), 'arguments' => $arguments, 'excepcion' => $ex->getMessage()], $flags));
        error_log('[opgg] fetch falló: ' . $ex->getMessage());
        // Sin conexion: mejor la ultima build conocida que nada.
        $vieja = leerJson($cacheFile);
        return esBuildValida($vieja) ? $vieja : null;
    }

    escribirAtomico($debugFile, json_encode(['timestamp' => date('c'), 'arguments' => $arguments, 'parsed' => $parsed], $flags));

    $data = $parsed['data'] ?? null;
    if (esBuildValida($data)) {
        escribirAtomico($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $data;
    }
    return null;
}

/*
  Filtros EXTRA que acepte la herramienta de OP.GG (elo, region...),
  descubiertos con tools/list. Se cachea 24 h. No se ofrecen filtros
  de modo de juego / cola (siempre clasificatoria de la Grieta) ni
  valores de formatos que MAZTERLAG no cubre (ej. LoL Classic).
*/
function parametrosExtraAnalisis(): array
{
    $file = rutaCache('opgg/_schema.json');
    $c = leerJson($file, 24 * 3600);
    if ($c !== null) {
        return $c;
    }
    $out = [];
    try {
        $res = mcpCallTool(OPGG_MCP_ENDPOINT, '', [], 'tools/list');
        foreach ($res['tools'] ?? [] as $t) {
            if (($t['name'] ?? '') !== 'lol_get_champion_analysis') {
                continue;
            }
            foreach ($t['inputSchema']['properties'] ?? [] as $nombre => $def) {
                $nombre = (string) $nombre;
                if (in_array($nombre, ['champion', 'position', 'game_mode', 'lang', 'desired_output_fields'], true) || preg_match('/mode|queue|map/i', $nombre)) {
                    continue;
                }
                $vals = array_values(array_filter(array_map('strval', $def['enum'] ?? []), fn($v) => !preg_match(NOMBRES_EXCLUIDOS_REGEX, $v)));
                if (count($vals) >= 2 && count($vals) <= 15) {
                    $etiquetas = ['tier' => 'Elo', 'region' => 'Región', 'version' => 'Parche'];
                    $out[] = ['name' => $nombre, 'label' => $etiquetas[$nombre] ?? ucfirst(str_replace('_', ' ', $nombre)), 'values' => $vals];
                }
            }
        }
    } catch (Throwable $ex) {
        error_log('[opgg] tools/list fallo: ' . $ex->getMessage());
        escribirAtomico($file, json_encode($out));
        @touch($file, time() - 23 * 3600); // fallo de red: se reintenta en 1 hora
        return $out;
    }
    escribirAtomico($file, json_encode($out));
    return $out;
}
