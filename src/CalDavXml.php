<?php

namespace Justproof\IcloudCalendar;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Minimal WebDAV multistatus reader: one entry per response, with the 200 props by local name.
 */
final class CalDavXml
{
    public const NS_DAV = 'DAV:';

    public const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    public const NS_APPLE = 'http://apple.com/ns/ical/';

    /**
     * @return list<array{href: string, props: array<string, DOMElement>}>
     */
    public static function multistatus(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::NS_DAV);

        $entries = [];

        foreach ($xpath->query('//d:response') ?: [] as $response) {
            $href = trim((string) $xpath->evaluate('string(d:href)', $response));
            $props = [];

            foreach ($xpath->query('d:propstat', $response) ?: [] as $propstat) {
                $status = (string) $xpath->evaluate('string(d:status)', $propstat);

                if ($status !== '' && ! str_contains($status, ' 200 ')) {
                    continue;
                }

                foreach ($xpath->query('d:prop/*', $propstat) ?: [] as $prop) {
                    if ($prop instanceof DOMElement) {
                        $props[$prop->localName] = $prop;
                    }
                }
            }

            $entries[] = ['href' => $href, 'props' => $props];
        }

        return $entries;
    }

    /**
     * The first d:href inside a property, or null.
     */
    public static function hrefIn(?DOMElement $prop): ?string
    {
        if ($prop === null) {
            return null;
        }

        foreach ($prop->getElementsByTagNameNS(self::NS_DAV, 'href') as $href) {
            $value = trim($href->textContent);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function hasChild(?DOMElement $prop, string $namespace, string $localName): bool
    {
        return $prop !== null && $prop->getElementsByTagNameNS($namespace, $localName)->length > 0;
    }

    /**
     * Names listed in a supported-calendar-component-set, or [] when the server did not say.
     *
     * @return list<string>
     */
    public static function components(?DOMElement $prop): array
    {
        if ($prop === null) {
            return [];
        }

        $names = [];

        foreach ($prop->getElementsByTagNameNS(self::NS_CALDAV, 'comp') as $comp) {
            $names[] = strtoupper((string) $comp->getAttribute('name'));
        }

        return $names;
    }
}
