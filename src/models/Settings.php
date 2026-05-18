<?php

namespace furbo\craftlinkchecker\models;

use craft\base\Model;

class Settings extends Model
{
    public string $skippedHosts = "twitter.com\nx.com\nfacebook.com\nfb.com\nfb.me\nlinkedin.com\ninstagram.com\nyoutube.com\nyoutu.be\ntiktok.com\npinterest.com\nxing.com\nthreads.net\nmastodon.social\nbsky.app\nsbb.ch";

    public string $skippedPathPrefixes = "/actions/";

    public function getSkippedHostsArray(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $this->skippedHosts)))));
    }

    public function getSkippedPathPrefixesArray(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $this->skippedPathPrefixes)))));
    }
}
