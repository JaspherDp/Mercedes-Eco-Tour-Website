<?php
declare(strict_types=1);

function ensureHotelReviewManagementColumns(PDO $pdo): void
{
    $columns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='hotel_resort_reviews'")->fetchAll(PDO::FETCH_COLUMN);
    $existing=array_fill_keys(array_map('strtolower',$columns),true);
    $changes=[
        'moderation_status'=>"ADD COLUMN moderation_status ENUM('published','hidden','flagged') NOT NULL DEFAULT 'published' AFTER review_message",
        'owner_reply'=>"ADD COLUMN owner_reply TEXT NULL AFTER moderation_status",
        'internal_note'=>"ADD COLUMN internal_note TEXT NULL AFTER owner_reply",
        'responded_by'=>"ADD COLUMN responded_by INT NULL AFTER internal_note",
        'responded_at'=>"ADD COLUMN responded_at DATETIME NULL AFTER responded_by",
        'updated_at'=>"ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach($changes as$column=>$definition)if(!isset($existing[$column]))$pdo->exec("ALTER TABLE hotel_resort_reviews {$definition}");
}

function hotelReviewProfileImage(?string $value): string
{
    $value=trim((string)$value);if($value==='')return'';
    if(preg_match('#^https?://#i',$value)||str_starts_with($value,'//'))return$value;
    $clean=ltrim(str_replace('\\','/',$value),'/');
    foreach(array_unique([$clean,'uploads/profile_pictures/'.basename($clean),'uploads/profile_picture/'.basename($clean),'img/'.basename($clean)])as$candidate){
        if(is_file(dirname(__DIR__).'/'.$candidate))return$candidate;
    }
    return'';
}

function hotelReviewInitials(string $name): string
{
    $parts=preg_split('/\s+/',trim($name))?:[];
    return strtoupper(substr((string)($parts[0]??'G'),0,1).(count($parts)>1?substr((string)end($parts),0,1):''));
}
