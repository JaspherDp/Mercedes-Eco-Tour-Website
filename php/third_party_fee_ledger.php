<?php
declare(strict_types=1);
require_once __DIR__ . '/additional_fees_helper.php';
require_once __DIR__ . '/payout_accounting.php';

final class ThirdPartyFeeLedger
{
    public const SCHEMA = "CREATE TABLE IF NOT EXISTS third_party_fee_payouts (
      payout_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      fee_category VARCHAR(20) NOT NULL, location_name VARCHAR(190) NOT NULL,
      amount_minor BIGINT UNSIGNED NOT NULL, settlement_method VARCHAR(10) NOT NULL,
      channel VARCHAR(100) NULL, reference_number VARCHAR(120) NULL,
      recipient_name VARCHAR(190) NOT NULL, note VARCHAR(500) NULL,
      allocations LONGTEXT NOT NULL, request_token CHAR(64) NOT NULL,
      settled_by_admin_id INT NOT NULL, settled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (payout_id), UNIQUE KEY uq_third_party_request (request_token),
      KEY idx_third_party_location (fee_category, location_name(120)), KEY idx_third_party_settled (settled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public static function ready(PDO $pdo): bool { return PayoutAccounting::tableExists($pdo, 'third_party_fee_payouts'); }
    public static function location(string $name): string {
        return trim((string)preg_replace('/\s+[—–-]\s+(?:day tour|overnight)$/ui', '', $name));
    }
    public static function key(string $category, string $location): string { return hash('sha256', $category . '|' . strtolower(self::location($location))); }
    public static function build(array $bookings, array $catalog, array $payments): array
    {
        $pools=[];
        $add=function(string $category,string $location)use(&$pools):string{
            $location=self::location($location);$key=self::key($category,$location);
            if(!isset($pools[$key]))$pools[$key]=['key'=>$key,'category'=>$category,'location'=>$location,'rates'=>[],'collected'=>[],'settled'=>[],'history'=>[]];
            return $key;
        };
        foreach($catalog as $fee){
            $category=strtolower($fee['category']);if(!in_array($category,['entrance','docking','environmental'],true))continue;
            $location=$category==='environmental'?'Environmental fee collections':self::location($fee['label']);
            $key=$add($category,$location);
            $pools[$key]['rates'][]=['label'=>$fee['label'],'amount_minor'=>PayoutAccounting::minor($fee['amount']),'unit'=>$fee['unit']];
        }
        $seenBookings=[];
        foreach($bookings as $booking){
            $bookKey=$booking['booking_domain'].':'.$booking['booking_id'];
            if(isset($seenBookings[$bookKey]))continue;$seenBookings[$bookKey]=true;
            $a=$booking['accounting'];
            if($a['active_refund']||str_contains($a['blocked_reason'],'reconciliation')||str_contains($a['blocked_reason'],'Currency')||str_contains($a['blocked_reason'],'inconsistent'))continue;
            foreach(['entrance','docking'] as $category)foreach($a['booking_fee_locations_minor'][$category]??[] as $location=>$amount){
                $key=$add($category,$location);$pools[$key]['collected'][$bookKey]=($pools[$key]['collected'][$bookKey]??0)+$amount;
            }
            $amount=$a['booking_fees_minor']['environmental']??0;
            if($amount>0){$key=$add('environmental','Environmental fee collections');$pools[$key]['collected'][$bookKey]=($pools[$key]['collected'][$bookKey]??0)+$amount;}
        }
        foreach($payments as $payment){
            $key=$add($payment['fee_category'],$payment['location_name']);
            $allocations=json_decode($payment['allocations'],true,512,JSON_THROW_ON_ERROR);
            foreach($allocations as $bookKey=>$amount)$pools[$key]['settled'][$bookKey]=($pools[$key]['settled'][$bookKey]??0)+(int)$amount;
            $payment['allocations']=$allocations;$pools[$key]['history'][]=$payment;
        }
        uasort($pools,static fn($a,$b)=>[$a['category'],$a['location']]<=>[$b['category'],$b['location']]);
        return array_values($pools);
    }
    public static function balance(array $pool,array $bookKeys): array {
        $scope=array_fill_keys($bookKeys,true);$collected=0;$settled=0;
        foreach($pool['collected'] as $key=>$amount)if(isset($scope[$key]))$collected+=$amount;
        foreach($pool['settled'] as $key=>$amount)if(isset($scope[$key]))$settled+=$amount;
        return ['collected'=>$collected,'settled'=>$settled,'available'=>max(0,$collected-$settled)];
    }
    public static function statTotals(array $pools,array $bookKeys): array {
        $totals=['available'=>0,'settled'=>0];
        foreach($pools as $pool){$balance=self::balance($pool,$bookKeys);$totals['available']+=$balance['available'];$totals['settled']+=$balance['settled'];}
        return $totals;
    }
    public static function allocate(array $pool,array $bookKeys,int $amount): array {
        $balance=self::balance($pool,$bookKeys);
        if($amount<=0||$amount>$balance['available'])throw new RuntimeException('The amount exceeds the current balance. Refresh the collection details and try again.');
        $allocations=[];$remaining=$amount;
        foreach(array_unique($bookKeys) as $key){
            $available=max(0,($pool['collected'][$key]??0)-($pool['settled'][$key]??0));
            $part=min($available,$remaining);if($part>0){$allocations[$key]=$part;$remaining-=$part;}if($remaining===0)break;
        }
        if($remaining!==0)throw new RuntimeException('The collection balance changed. Refresh and try again.');
        return $allocations;
    }
    public static function validate(array $input): array {
        if(($input['fee_confirmed']??'')!=='1')throw new RuntimeException('Confirm that the recipient has already been paid.');
        $amount=trim((string)($input['fee_amount']??''));
        if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$amount))throw new RuntimeException('Enter a valid payout amount.');
        $minor=PayoutAccounting::minor($amount);if($minor<=0)throw new RuntimeException('Enter an amount greater than zero.');
        $method=(string)($input['fee_method']??'');if(!in_array($method,['cash','online'],true))throw new RuntimeException('Choose cash or send money online.');
        $recipient=trim((string)($input['fee_recipient']??''));$channel=trim((string)($input['fee_channel']??''));$reference=trim((string)($input['fee_reference']??''));$note=trim((string)($input['fee_note']??''));
        if($recipient===''||mb_strlen($recipient)>190)throw new RuntimeException('Enter the recipient or location owner’s name.');
        if($method==='online'&&($channel===''||$reference===''))throw new RuntimeException('Enter the transfer channel and reference number.');
        if(mb_strlen($channel)>100||mb_strlen($reference)>120||mb_strlen($note)>500)throw new RuntimeException('Some transfer details are too long.');
        if($method==='cash')$channel='';
        $token=(string)($input['fee_request_token']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Refresh the page before recording a payout.');
        return compact('minor','method','recipient','channel','reference','note','token');
    }
}
