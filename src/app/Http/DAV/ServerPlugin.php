<?php

namespace App\Http\DAV;

use Sabre\DAV\Server;
use Sabre\Xml\Deserializer;
use Sabre\Xml\Reader;

/**
 * A plugin covering Kolab XML extensions.
 *
 * Plugins can modifies/extends the Sabre server behaviour.
 */
class ServerPlugin extends \Sabre\DAV\ServerPlugin
{
    /**
     * This initializes the plugin.
     *
     * This function is called by Sabre\DAV\Server, after addPlugin is called.
     */
    public function initialize(Server $server)
    {
        // Tell the XML parser how to handle structured Kolab properties
        $server->xml->elementMap['{Kolab:}links'] = function (Reader $reader) {
            return Deserializer\repeatingElements($reader, '{Kolab:}link');
        };

        $server->xml->elementMap['{Kolab:}categories'] = function (Reader $reader) {
            return Deserializer\repeatingElements($reader, '{Kolab:}category');
        };
    }
}
