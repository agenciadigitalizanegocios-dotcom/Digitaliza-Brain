<?php

namespace App\Catalog;

use App\Fields\FieldTypes;
use App\Fields\ImageStore;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductType;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Importa produtos de uma API JSON (ex.: o site de imóveis atual da BS).
 * O "mapa" diz de qual campo da API vem cada campo do catálogo; vários nomes separados por "|"
 * (usa o primeiro que existir). Rodar de novo atualiza pelo id de origem, sem duplicar.
 */
final class ProductImporter
{
    /** Mapa padrão pensado para o site de imóveis da BS. Ajuste com --mapa se os nomes forem outros. */
    public const DEFAULT_MAP = [
        'lista' => 'data|items|imoveis|properties',
        'id' => 'id|uuid|codigo',
        'campos' => [
            // Nunca usar o endereço como título: o título aparece na vitrine e no Google.
            'titulo' => 'titulo|title|nome',
            'tipo' => 'tipo|type|categoria',
            'aluguel' => 'aluguel|preco|price|valor|rent',
            'condominio' => 'condominio|condo',
            'iptu' => 'iptu',
            'quartos' => 'quartos|bedrooms|dormitorios',
            'banheiros' => 'banheiros|bathrooms',
            'vagas' => 'vagas|garagem|vagas_garagem|parking',
            'area' => 'area|area_m2|metragem',
            'bairro' => 'bairro|neighborhood',
            'cidade' => 'cidade|city',
            'endereco' => 'endereco|address',
            'aceita_pet' => 'aceita_pet|pet|aceita_pets|pets',
            'descricao' => 'descricao|description|detalhes',
        ],
        'fotos' => 'fotos|photos|imagens|images',
        'publicado' => 'mostrar_no_site|publicado|published|ativo|visivel',
        'status' => 'status|situacao',
        'status_map' => ['disponivel' => 'disponivel', 'alugado' => 'indisponivel', 'vendido' => 'indisponivel',
            'indisponivel' => 'indisponivel', 'reservado' => 'reservado'],
        'dinheiro_em_centavos' => false,
    ];

    private const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

    private array $report = ['criados' => 0, 'atualizados' => 0, 'pulados' => 0, 'fotos' => 0, 'avisos' => []];

    public function __construct(
        private Tenant $tenant,
        private ProductType $type,
        private string $url,
        private ?string $token = null,
        private array $map = self::DEFAULT_MAP,
    ) {
        $this->map = array_replace_recursive(self::DEFAULT_MAP, $map);
    }

    /** Lê todas as páginas da API. */
    public function fetch(int $maxPages = 50): array
    {
        $items = [];
        $next = $this->url;
        for ($page = 0; $next && $page < $maxPages; $page++) {
            // O token só vai para o mesmo endereço da API informada (nunca para um link vindo da resposta).
            if (! $this->sameOrigin($next)) {
                $this->report['avisos'][] = "Parei na página {$page}: o link da próxima página aponta para outro endereço ({$next}).";
                break;
            }
            $response = Http::timeout(30)->acceptJson()->withOptions(['allow_redirects' => false])
                ->when($this->token, fn ($h) => $h->withToken($this->token))
                ->get($next);
            if (! $response->successful()) {
                throw new InvalidArgumentException("A API respondeu {$response->status()} em {$next}.");
            }
            $json = $response->json();
            $list = array_is_list($json ?? []) ? $json : $this->pick($json, $this->map['lista']);
            if (! is_array($list)) {
                throw new InvalidArgumentException('Não achei a lista de itens na resposta. Ajuste "lista" no mapa.');
            }
            array_push($items, ...$list);
            $next = is_array($json) && ! array_is_list($json)
                ? ($this->pick($json, 'next_page_url|links.next|meta.next') ?: null)
                : null;
        }

        return $items;
    }

    /** Converte um item da API nos campos do catálogo. */
    public function mapItem(array $item): array
    {
        $fields = $this->type->activeFields();
        $data = [];
        foreach ($this->map['campos'] as $key => $source) {
            $f = $fields[$key] ?? null;
            if (! $f) {
                continue;
            }
            $value = $this->convert($f, $this->pick($item, $source));
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        $published = $this->pick($item, $this->map['publicado']);
        $rawStatus = mb_strtolower(trim((string) $this->pick($item, $this->map['status'])));

        return [
            'external_id' => (string) ($this->pick($item, $this->map['id']) ?? ''),
            'data' => $data,
            'published' => $published === null ? true : $this->truthy($published),
            'status' => $this->map['status_map'][$rawStatus] ?? 'disponivel',
            'photos' => $this->photoUrls($this->pick($item, $this->map['fotos'])),
        ];
    }

    /** Grava (ou atualiza pelo id de origem). */
    public function import(array $items, bool $photosAgain = false): array
    {
        app(CurrentTenant::class)->set($this->tenant);

        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $m = $this->mapItem($item);
            $title = $m['data'][$this->type->title_field] ?? '';
            if (trim((string) $title) === '') {
                $this->report['pulados']++;
                $this->report['avisos'][] = 'Item '.($i + 1).' sem título: pulado.';
                continue;
            }

            $product = $m['external_id'] !== ''
                ? Product::where('product_type_id', $this->type->id)->where('external_id', $m['external_id'])->first()
                : null;

            DB::transaction(function () use (&$product, $m) {
                $attrs = ['data' => $m['data'], 'published' => $m['published'], 'status' => $m['status']];
                if ($product) {
                    $product->update(['data' => array_merge($product->data ?? [], $m['data'])] + $attrs);
                    $this->report['atualizados']++;
                } else {
                    $product = Product::create($attrs + ['product_type_id' => $this->type->id, 'external_id' => $m['external_id'] ?: null]);
                    $this->report['criados']++;
                }
            });

            if ($m['photos'] && ($photosAgain || ! $product->images()->exists())) {
                $this->downloadPhotos($product, $m['photos']);
            }
        }

        return $this->report;
    }

    private function downloadPhotos(Product $product, array $urls): void
    {
        $position = (int) $product->images()->max('position');
        foreach (array_slice($urls, 0, Catalog::MAX_PHOTOS) as $url) {
            try {
                $binary = $this->downloadImage($url);
                $path = $binary !== null ? ImageStore::storeBinary($binary, $this->tenant->id) : null;
                if ($path) {
                    ProductImage::create(['product_id' => $product->id, 'path' => $path, 'position' => ++$position]);
                    $this->report['fotos']++;
                } else {
                    $this->report['avisos'][] = "Foto ignorada (não é jpg/png/webp ou passou de 5 MB): {$url}";
                }
            } catch (Throwable $e) {
                $this->report['avisos'][] = "Não baixei {$url}: ".$e->getMessage();
            }
        }
    }

    /**
     * Baixa uma foto com segurança: só http(s), sem seguir redirecionamento, no máximo 5 MB,
     * e nunca de um endereço da rede interna (a não ser o próprio servidor da API informada).
     */
    private function downloadImage(string $url): ?string
    {
        $p = parse_url($url);
        if (! in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
            throw new InvalidArgumentException('endereço inválido');
        }
        if (! $this->sameOrigin($url) && ! $this->isPublicHost($p['host'])) {
            throw new InvalidArgumentException('endereço da rede interna recusado');
        }

        $res = Http::timeout(30)->withOptions([
            'allow_redirects' => false,
            'progress' => function ($total, $downloaded) {
                if ($total > self::MAX_PHOTO_BYTES || $downloaded > self::MAX_PHOTO_BYTES) {
                    throw new InvalidArgumentException('foto acima de 5 MB');
                }
            },
        ])->get($url);

        return $res->successful() ? $res->body() : null;
    }

    private function sameOrigin(string $url): bool
    {
        $a = parse_url($this->url);
        $b = parse_url($url);

        return ($a['scheme'] ?? null) === ($b['scheme'] ?? null)
            && mb_strtolower($a['host'] ?? '') === mb_strtolower($b['host'] ?? '')
            && ($a['port'] ?? null) === ($b['port'] ?? null);
    }

    private function isPublicHost(string $host): bool
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return $ips !== [];
    }

    private function convert(array $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field['type']) {
            'money' => $this->money($value),
            'number' => is_numeric($value) ? (int) round((float) $value) : (preg_match('/\d+/', (string) $value, $n) ? (int) $n[0] : null),
            'checkbox' => $this->truthy($value),
            'select' => $this->option($field, (string) $value),
            'image' => null, // fotos vão para a galeria
            default => trim(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)),
        };
    }

    private function money(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return $this->map['dinheiro_em_centavos'] ? (int) $value : (int) round(((float) $value) * 100);
        }
        try {
            return FieldTypes::parseMoney((string) $value);
        } catch (InvalidArgumentException) {
            $this->report['avisos'][] = "Valor em dinheiro não entendido: {$value}";

            return null;
        }
    }

    private function option(array $field, string $value): ?string
    {
        $needle = mb_strtolower(trim($value));
        foreach ($field['options'] ?? [] as $key => $label) {
            if ($needle === mb_strtolower((string) $key) || $needle === mb_strtolower($label)) {
                return (string) $key;
            }
        }
        $this->report['avisos'][] = "“{$value}” não existe nas opções de “{$field['label']}”.";

        return null;
    }

    private function truthy(mixed $v): bool
    {
        return in_array(mb_strtolower(trim((string) (is_bool($v) ? ($v ? '1' : '0') : $v))), ['1', 'true', 'sim', 's', 'yes', 'on'], true);
    }

    /** Fotos podem vir como lista de URLs, lista de objetos {url|path|src} ou texto separado por vírgula. */
    private function photoUrls(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?? array_map('trim', explode(',', $value));
        }
        $urls = [];
        foreach ((array) $value as $v) {
            $u = is_array($v) ? ($v['url'] ?? $v['path'] ?? $v['src'] ?? null) : $v;
            if (is_string($u) && trim($u) !== '') {
                $urls[] = $this->absolute(trim($u));
            }
        }

        return array_values(array_unique($urls));
    }

    private function absolute(string $u): string
    {
        if (preg_match('#^https?://#', $u)) {
            return $u;
        }
        $p = parse_url($this->url);
        $base = ($p['scheme'] ?? 'http').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '');

        return $base.'/'.ltrim($u, '/');
    }

    /** Primeiro caminho (a|b|c.d) que existir no item. */
    private function pick(mixed $data, string $paths): mixed
    {
        if (! is_array($data)) {
            return null;
        }
        foreach (explode('|', $paths) as $path) {
            $v = Arr::get($data, trim($path));
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }
}
