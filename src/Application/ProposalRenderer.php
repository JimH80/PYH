<?php

declare(strict_types=1);

namespace PYH\Application;

use PYH\Security\Html;

final class ProposalRenderer
{
    /** @param array<string,mixed> $snapshot */
    public function render(array $snapshot): string
    {
        $quote=$snapshot['quote'];$travellers=$snapshot['travellers'];$components=$snapshot['components'];$pricing=$snapshot['pricing'];
        $travellerHtml='';foreach($travellers as $t){$travellerHtml.='<li>'.Html::escape((string)$t['display_name']).' · '.Html::escape((string)$t['traveller_type']).($t['is_lead']?' · Lead traveller':'').'</li>';}
        $arrangements='';foreach($components as $c){$arrangements.='<article class="proposal-card"><span>'.Html::escape((string)$c['component_type']).'</span><h3>'.Html::escape((string)$c['title']).'</h3><p>'.Html::escape((string)($c['customer_description']??'')).'</p><p>'.Html::escape((string)($c['origin']??'')).' → '.Html::escape((string)($c['destination']??'')).'</p></article>';}
        $optional='';foreach($snapshot['optional_extras'] as $c){$optional.='<li>'.Html::escape((string)$c['title']).' — '.Html::escape((string)$quote['currency'].' '.$c['selling_price']).'</li>';}
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'.Html::escape((string)$quote['title']).'</title></head><body class="proposal proposal-'.Html::escape(strtolower(str_replace(' ','-',(string)$quote['product_type']))).'"><header><small>'.Html::escape((string)$quote['reference']).' · Proposal '.$quote['revision_number'].'</small><h1>'.Html::escape((string)$quote['title']).'</h1><p>'.Html::escape((string)($quote['customer_introduction']??'')).'</p></header><main><section><h2>Your party</h2><ul>'.$travellerHtml.'</ul></section><section><h2>Your holiday arrangements</h2>'.$arrangements.'</section><section><h2>What is included</h2><p>'.Html::escape((string)$snapshot['inclusions']).'</p></section><section><h2>Optional extras</h2><ul>'.$optional.'</ul></section><section><h2>Your price</h2><strong>'.Html::escape((string)$quote['currency'].' '.$pricing['customer_total']).'</strong><p>Deposit: '.Html::escape((string)$quote['currency'].' '.$pricing['deposit']).' · Balance: '.Html::escape((string)$quote['currency'].' '.$pricing['balance']).'</p></section><section><h2>Important information</h2><p>'.Html::escape((string)$snapshot['compliance_text']).'</p><p>Valid until '.Html::escape((string)$quote['expires_at_utc']).'. Price and availability remain subject to confirmation until booked.</p></section><footer><h2>Your consultant</h2><p>'.Html::escape((string)$snapshot['consultant']['display_name']).' · '.Html::escape((string)($snapshot['consultant']['email']??'')).'</p></footer></main></body></html>';
    }
}
