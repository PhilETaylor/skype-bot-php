<?php

namespace Skype\Api;

class Conversation extends BaseApi implements ApiInterface
{
    /**
     * Sends an activity message
     *
     * @param $target In format of 8:<username> or 19:<group>
     * @param $content The message
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function activity($target, $content)
    {
        return $this->request('POST', '/v3/conversations/' . $target . '/activities', [
            'json' => [
                'text' => $content,
                'type' => 'message/card.carousel',
            ]
        ]);
    }

    public function sendSiteCard($target, $siteName, $siteUrl)
    {
        $j = '{
"type":"message/card.carousel",
"summary":"Site details ' . $siteUrl . '",
"text":"Here is your website details",
"attachments":[
    {
    "contentType":"application/vnd.microsoft.card.hero",
    "content":{
        
            "title":"' . $siteName . '",
            "subtitle":"Wordpress Site",
            "text":"Last backup was never",
            "images":[
          
            ]
        ,
        "buttons":[
        {
            "type":"imBack",
            "title":"Backup Now",
            "value":"Backup ' . $siteUrl . '"
        },
        {
            "type":"imBack",
            "title":"Get Last Backup Details",
            "value":"Get Last Backup Details ' . $siteUrl . '"
        },
        {
            "type":"openUrl",
            "title":"Visit Site",
            "value":"' . $siteUrl . '"
        }
        ]
    }
    }
]
}';

        $j = json_decode($j);

        return $this->request('POST', '/v3/conversations/' . $target . '/activities', [
            'json' => $j
        ]);
    }
}
