<?php
declare(strict_types=1);
function catalog_games(PDO $pdo): array {
 return $pdo->query("SELECT id,slug,name,description,icon_url FROM games WHERE status='ACTIVE' ORDER BY sort_order,name")->fetchAll();
}
function catalog_banners(PDO $pdo): array {
 return $pdo->query("SELECT id,title,image_url,target_url FROM banners WHERE status='ACTIVE' ORDER BY sort_order,id DESC")->fetchAll();
}
function catalog_products(PDO $pdo,string $slug): array {
 $sql="SELECT p.id,p.name,p.provider_product_code AS code,p.base_price,p.selling_price,p.status,
 CASE WHEN p.status<>'ACTIVE' OR pr.status NOT IN ('ACTIVE','LOW_BALANCE') OR pr.balance IS NULL OR p.base_price>(pr.balance*(pr.reserve_percentage/100)) THEN 0 ELSE 1 END AS is_available
 FROM products p JOIN games g ON g.id=p.game_id JOIN providers pr ON pr.id=p.provider_id
 LEFT JOIN excluded_services ex ON ex.provider_id=p.provider_id AND ex.service_code=p.provider_product_code
 WHERE g.slug=:slug AND g.status='ACTIVE' AND ex.id IS NULL
 AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%joki%'
 AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%vilog%'
 ORDER BY p.selling_price,p.name";
 $stmt=$pdo->prepare($sql); $stmt->execute(['slug'=>$slug]); $rows=$stmt->fetchAll(); $chosen=[];
 foreach($rows as $row){
  $key=mb_strtolower(trim((string)$row['name']),'UTF-8'); if($key==='') continue;
  if(!isset($chosen[$key]) || (int)$row['is_available']>(int)$chosen[$key]['is_available'] ||
    ((int)$row['is_available']===(int)$chosen[$key]['is_available'] && (float)$row['selling_price']<(float)$chosen[$key]['selling_price'])) $chosen[$key]=$row;
 }
 $out=array_values($chosen);
 foreach($out as &$row){$row['base_price']=(float)$row['base_price'];$row['selling_price']=(float)$row['selling_price'];$row['is_available']=(bool)$row['is_available'];}
 unset($row);
 usort($out,static fn(array $a,array $b):int=>($a['selling_price']<=>$b['selling_price'])?:strcasecmp((string)$a['name'],(string)$b['name']));
 return $out;
}
