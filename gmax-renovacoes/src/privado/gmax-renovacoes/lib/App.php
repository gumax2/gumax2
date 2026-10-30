<?php
declare(strict_types=1);

namespace GmaxRenov;

use GmaxRenov\Provider\MetaCloudProvider;
use GmaxRenov\Provider\Provider;
use GmaxRenov\Provider\SimulatedProvider;

/**
 * Contêiner simples: configuração, banco, relógio, configurações e serviços.
 */
final class App
{
    public array $config;
    private ?Db $db = null;
    private Clock $clock;
    private ?Settings $settings = null;
    private ?PanelData $panel = null;
    private ?Log $log = null;
    private ?Provider $providerOverride = null;

    public function __construct(array $config, ?Clock $clock = null)
    {
        $this->config = $config;
        $tz = $config['fuso'] ?? 'America/Sao_Paulo';
        date_default_timezone_set($tz);
        $this->clock = $clock ?? new SystemClock($tz);
    }

    public function db(): Db
    {
        return $this->db ??= Db::fromConfig($this->config['db'] ?? []);
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function setClock(Clock $clock): void
    {
        $this->clock = $clock;
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db(), $this->clock);
    }

    public function panel(): PanelData
    {
        return $this->panel ??= new PanelData($this->db(), $this->config['esquema'] ?? []);
    }

    public function log(): Log
    {
        return $this->log ??= new Log($this->config['pasta_logs'] ?? (GR_ROOT . '/logs'), $this->clock);
    }

    /** Usado apenas em testes para injetar um provedor roteirizado. */
    public function setProvider(?Provider $p): void
    {
        $this->providerOverride = $p;
    }

    /**
     * Provedor efetivo. O envio real só acontece quando TODAS as condições abaixo valem:
     * driver "meta_cloud" no config.php, modo "real" nas configurações, Pix confirmado.
     * Em qualquer outro caso, usa o provedor simulado (sem rede).
     */
    public function provider(): Provider
    {
        if ($this->providerOverride) {
            return $this->providerOverride;
        }
        if ($this->realSendingAllowed()) {
            return new MetaCloudProvider($this->config['provedor']['meta'] ?? [], $this->log());
        }
        return new SimulatedProvider();
    }

    public function driver(): string
    {
        return (string)($this->config['provedor']['driver'] ?? 'simulado');
    }

    /** Motivos que impedem o envio real (lista vazia = permitido). */
    public function realSendingBlockers(): array
    {
        $s = $this->settings();
        $b = [];
        if ($this->driver() !== 'meta_cloud') {
            $b[] = 'Provedor real não configurado no config.php (driver = simulado).';
        } else {
            $m = $this->config['provedor']['meta'] ?? [];
            foreach (['phone_number_id', 'access_token', 'app_secret', 'verify_token'] as $k) {
                if (trim((string)($m[$k] ?? '')) === '') {
                    $b[] = "Credencial ausente no config.php: provedor.meta.$k";
                }
            }
        }
        if ($s->get('modo') !== 'real') {
            $b[] = 'Modo de simulação ativo nas configurações.';
        }
        if (!$s->get('pagamento_confirmado')) {
            $b[] = 'Instruções de pagamento/chave Pix ainda não confirmadas.';
        }
        foreach (Stages::all($s) as $st) {
            if (!$st['ativo']) {
                continue;
            }
            $tpl = $s->get('modelos')[$st['codigo']] ?? [];
            if (trim((string)($tpl['individual'] ?? '')) === '' || trim((string)($tpl['grupo'] ?? '')) === '') {
                $b[] = 'Nome do modelo aprovado (Meta) não informado para a etapa "' . $st['rotulo'] . '".';
            }
        }
        return $b;
    }

    public function realSendingAllowed(): bool
    {
        return $this->realSendingBlockers() === [];
    }

    /** "real" somente quando o envio real está liberado; caso contrário "simulacao". */
    public function effectiveMode(): string
    {
        return $this->realSendingAllowed() ? 'real' : 'simulacao';
    }
}
