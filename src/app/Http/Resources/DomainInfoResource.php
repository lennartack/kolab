<?php

namespace App\Http\Resources;

use App\Domain;
use App\Http\Controllers\API\V4\DomainsController;
use Illuminate\Http\Request;

/**
 * Domain information response
 */
class DomainInfoResource extends DomainResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            $this->merge(parent::toArray($request)),

            // @var int Domain status
            'status' => $this->resource->status,

            // Domain DNS hash
            'hash_text' => $this->resource->hash(Domain::HASH_TEXT),
            // Domain DNS hash
            'hash_cname' => $this->resource->hash(Domain::HASH_CNAME),
            // Domain DNS hash
            'hash_code' => $this->resource->hash(Domain::HASH_CODE),

            // DNS configuration for the domain
            'dns' => self::getDNSConfig($this->resource),
            // MX configuration for the domain
            'mx' => self::getMXConfig($this->resource),

            // @var array<string, mixed> Domain configuration
            'config' => $this->resource->getConfig(),

            // @var array Extended status/permissions information
            'statusInfo' => DomainsController::statusInfo($this->resource),

            // Entitlements/Wallet information
            $this->merge($this->objectEntitlements()),
        ];
    }

    /**
     * Provide DNS MX information to configure specified domain for
     */
    protected static function getMXConfig(Domain $domain): array
    {
        $namespace = $domain->namespace;
        $entries = [];

        // copy MX entries from an existing domain
        if ($master = \config('dns.copyfrom')) {
            // TODO: cache this lookup
            foreach ((array) dns_get_record($master, \DNS_MX) as $entry) {
                $entries[] = sprintf(
                    "@\t%s\t%s\tMX\t%d %s.",
                    \config('dns.ttl', $entry['ttl']),
                    $entry['class'],
                    $entry['pri'],
                    $entry['target']
                );
            }
        } elseif ($static = \config('dns.static')) {
            $entries[] = strtr($static, ['\n' => "\n", '%s' => $namespace]);
        }

        // display SPF settings
        if ($spf = \config('dns.spf')) {
            $entries[] = ';';
            foreach (['TXT', 'SPF'] as $type) {
                $entries[] = sprintf(
                    "@\t%s\tIN\t%s\t\"%s\"",
                    \config('dns.ttl'),
                    $type,
                    $spf
                );
            }
        }

        return $entries;
    }

    /**
     * Provide sample DNS config for domain confirmation
     */
    protected static function getDNSConfig(Domain $domain): array
    {
        $hash_txt = $domain->hash(Domain::HASH_TEXT);

        return [
            "{$domain->namespace}. TXT \"{$hash_txt}\"",
        ];
    }
}
