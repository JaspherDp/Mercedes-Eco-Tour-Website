<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/walkin_booking_helper.php';
function walkinCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function walkinReject(callable $work, string $message): void {
    try { $work(); } catch (Throwable $error) {
        walkinCheck(str_contains($error->getMessage(), $message), 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

// Temporary tables shadow application tables in this connection only. No real
// bookings, catalog rows, payments, or emails are created or changed.
$cancellationSchema = $pdo->query('SHOW CREATE TABLE booking_cancellation_requests')->fetch(PDO::FETCH_NUM)[1];
$pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $cancellationSchema));
$pdo->exec('CREATE TEMPORARY TABLE bookings (booking_id INT PRIMARY KEY, boat_id INT NULL, guide_id INT NULL, booking_date DATE, tour_type VARCHAR(30), tour_range VARCHAR(80), status VARCHAR(30), is_complete VARCHAR(30))');
$pdo->exec('CREATE TEMPORARY TABLE booking_checkout_drafts (booking_id INT, booking_domain VARCHAR(30), status VARCHAR(30), expires_at DATETIME, payload LONGTEXT)');
$pdo->exec('CREATE TEMPORARY TABLE booking_resource_boats (booking_id INT, boat_id INT, PRIMARY KEY(booking_id,boat_id))');
$pdo->exec('CREATE TEMPORARY TABLE booking_resource_guides (booking_id INT, guide_id INT, PRIMARY KEY(booking_id,guide_id))');
$pdo->exec('CREATE TEMPORARY TABLE booking_walkin_details (booking_id INT PRIMARY KEY, details LONGTEXT)');
$pdo->exec('CREATE TEMPORARY TABLE boats (boat_id INT PRIMARY KEY, name VARCHAR(100), total_pax INT)');
$pdo->exec('CREATE TEMPORARY TABLE tour_guides (guide_id INT PRIMARY KEY, fullname VARCHAR(100))');
$pdo->exec('CREATE TEMPORARY TABLE service_prices (service_type VARCHAR(40), day_tour_price DECIMAL(12,2), overnight_price DECIMAL(12,2), is_active INT)');
$pdo->exec('CREATE TEMPORARY TABLE tour_packages (package_id INT, operator_id INT, package_title VARCHAR(100), price DECIMAL(12,2), package_type VARCHAR(30), package_range VARCHAR(60))');
$pdo->exec('CREATE TEMPORARY TABLE additional_fees (fee_code VARCHAR(80), category VARCHAR(50), fee_label VARCHAR(100), amount DECIMAL(12,2), unit VARCHAR(30), is_active INT)');
$pdo->exec("INSERT INTO boats VALUES (1,'Test boat A',8),(2,'Test boat B',8),(3,'Unavailable primary',8),(4,'Unavailable extra',8),(5,'Test boat C',8)");
$pdo->exec("INSERT INTO tour_guides VALUES (1,'Test guide'),(2,'Second guide')");
$pdo->exec("INSERT INTO service_prices VALUES ('boat',2500,3500,1),('tourguide',700,1400,1)");
$pdo->exec("INSERT INTO tour_packages VALUES (1,1,'Test day package',1599,'same-day','1 day'),(2,1,'Test overnight package',2000,'overnight','2 days 1 night')");
$pdo->exec("INSERT INTO bookings VALUES (901,3,NULL,'2099-01-02','overnight','2099-01-02 to 2099-01-04','accepted','uncomplete')");
$pdo->exec('INSERT INTO booking_resource_boats VALUES (901,3),(901,4)');

$input = ['booking_type'=>'boat','tour_type'=>'same-day','booking_date'=>'2099-01-03', 'num_adults'=>'8','num_children'=>'1','child_ages'=>['5'],
    'boat_id'=>'1','additional_boat_ids'=>['2'],'locations'=>['Malasugui Island','Apuao Grande Island'],'eco_category'=>'local'];
$quote = WalkinBookingQuote($pdo, $input);
walkinCheck($quote['capacity']===16 && $quote['boat_ids']===[1,2], 'Both boats must be selected and provide combined capacity.');
walkinCheck($quote['total']===6270.0 && $quote['entrance']===270.0 && $quote['docking']===600.0, 'All nine passengers must count toward entrance fees; environmental fees use only adults.');
walkinCheck(abs(array_sum(array_column(array_merge($quote['service_rows'],$quote['fee_rows']),'amount'))-$quote['total'])<0.009, 'Itemized amounts must reconcile.');
walkinReject(fn()=>WalkinBookingQuote($pdo, array_replace($input,['additional_boat_ids'=>[]])), 'more seats');
walkinReject(fn()=>WalkinBookingQuote($pdo, array_replace($input,['additional_boat_ids'=>['1']])), 'only once');
walkinReject(fn()=>WalkinBookingQuote($pdo, array_replace($input,['additional_boat_ids'=>['2','5']])), 'only the additional boats needed');
walkinReject(fn()=>WalkinBookingQuote($pdo, array_replace($input,['additional_boat_ids'=>['4']])), 'unavailable');
walkinReject(fn()=>WalkinBookingQuote($pdo, array_replace($input,['boat_id'=>'3','additional_boat_ids'=>['2']])), 'unavailable');
walkinReject(fn()=>WalkinBookingQuote($pdo, array_replace($input,['locations'=>['Canimog Island','Caringo Island','Malasugui Island']])), 'two different');
walkinReject(fn()=>WalkinBookingQuote($pdo, array_replace($input,['locations'=>['Canimog Island','Canimog Island']])), 'two different');
walkinCheck(WalkinBookingQuote($pdo, array_replace($input,['child_ages'=>[]]))['total']===6270.0, 'No age details or second child count are required.');
$large = WalkinBookingQuote($pdo, array_replace($input,['num_adults'=>'17','num_children'=>'0','child_ages'=>[],'additional_boat_ids'=>['2','5']]));
walkinCheck($large['capacity']===24 && $large['total']===9760.0, 'More than two boats must be supported and charged.');
walkinReject(fn()=>WalkinBookingQuote($pdo, array_replace($input,['num_adults'=>'8','num_children'=>'0','child_ages'=>[]])), 'only the additional boats needed');
$overnight = WalkinBookingQuote($pdo, array_replace($input,['tour_type'=>'overnight','booking_end_date'=>'2099-01-05','locations'=>['Canimog Island']]));
walkinCheck($overnight['service_amount']===7000.0 && $overnight['entrance']===2250.0 && $overnight['docking']===1000.0, 'Overnight and Canimog fees must use the configured overnight rates.');
$guide = WalkinBookingQuote($pdo, array_replace($input,['booking_type'=>'tourguide','guide_id'=>'1','additional_guide_ids'=>['2'],'include_boats'=>'1']));
walkinCheck($guide['service_amount']===6400.0 && $guide['total']===7670.0, 'Guide bookings may include multiple boats.');
$guideOnly = WalkinBookingQuote($pdo, array_replace($input,['booking_type'=>'tourguide','guide_id'=>'1','additional_guide_ids'=>['2'],'include_boats'=>'']));
walkinCheck($guideOnly['service_amount']===1400.0 && $guideOnly['docking']===0.0 && !$guideOnly['boat_ids'], 'Guide-only bookings must not reserve or charge boats.');
$catalog=WalkinBookingCatalog($pdo,'2099-01-04','2099-01-05');
$catalogById = array_column($catalog['boats'], null, 'boat_id');
walkinCheck(count($catalog['boats'])===5 && !$catalogById[3]['available'] && !$catalogById[4]['available'], 'Unavailable boats must remain in the catalog.');
walkinCheck(!tourResourceIsAvailable($pdo,'boat',4,'2099-01-04'), 'Additional reserved boat must block all other booking flows, including end date.');
walkinCheck(tourResourceIsAvailable($pdo,'boat',4,'2099-01-05'), 'Boat becomes available after the overnight end date.');
$pdo->exec("UPDATE bookings SET status='cancelled' WHERE booking_id=901");
walkinCheck(tourResourceIsAvailable($pdo,'boat',4,'2099-01-03'), 'Cancelled reservations release their additional boats.');
$package = array_replace($input,['booking_type'=>'package','package_id'=>'1','locations'=>[], 'num_adults'=>'2','num_children'=>'0','child_ages'=>[]]);
$packageQuote=WalkinBookingQuote($pdo,$package,false);
walkinCheck($packageQuote['total']===3298.0, 'Package per-guest price plus environmental fees must be applied.');
walkinReject(fn()=>WalkinBookingQuote($pdo,array_replace($package,['tour_type'=>'overnight','booking_end_date'=>'2099-01-04']),false),'tour type and duration');
$pdo->prepare('INSERT INTO booking_walkin_details VALUES (?,?)')->execute([999,json_encode($quote)]);
$receipt=PaymentReceiptTourBreakdown($pdo,['booking_id'=>999,'booking_type'=>'boat','preferred_resource'=>'Walk-in booking'],5000.0);
walkinCheck(count($receipt['rows'])===2 && $receipt['rows'][1]['description']==='Boat hire — Test boat B','Receipt must retain both boats without duplicating fees.');
echo "PASS: multi-boat capacity, date availability, overnight prices, two destinations, fee exemptions, guide transport, packages, and itemized receipts.\n";
$unpaid = WalkinBookingPayment(['payment_option'=>'full','payment_amount'=>'0.00'],2880.0);
walkinCheck($unpaid === ['amount'=>0.0,'balance'=>2880.0,'method'=>''], 'New walk-ins must remain unpaid until collection is confirmed.');
$paid = WalkinBookingPayment(['payment_option'=>'full','payment_amount'=>'2880.00','payment_collected'=>'1','payment_method'=>'Cash'],2880.0);
walkinCheck($paid === ['amount'=>2880.0,'balance'=>0.0,'method'=>'cash'], 'Full payment needs explicit collection and a method.');
walkinReject(fn()=>WalkinBookingPayment(['payment_option'=>'partial','payment_amount'=>'576.00'],2880.0), 'full payment only');
walkinReject(fn()=>WalkinBookingPayment(['payment_option'=>'full','payment_amount'=>'576.00','payment_collected'=>'1','payment_method'=>'Cash'],2880.0), 'Partial payments are not allowed');
walkinReject(fn()=>WalkinBookingPayment(['payment_option'=>'full','payment_amount'=>'2880.00','payment_method'=>'Cash'],2880.0), 'actually been collected');
walkinReject(fn()=>WalkinBookingPayment(['payment_option'=>'full','payment_amount'=>'2880.00','payment_collected'=>'1'],2880.0), 'method used');
echo "PASS: unpaid default, explicit full collection, payment method, and rejection of down payments or unconfirmed payments.\n";
$qrPending = WalkinBookingPayment(['payment_option'=>'full','payment_amount'=>'0.00','payment_method'=>'qr_code'],2880.0);
walkinCheck($qrPending === ['amount'=>0.0,'balance'=>2880.0,'method'=>''], 'Creating a QR must never mark a payment as received.');
walkinReject(fn()=>WalkinBookingPayment(['payment_option'=>'full','payment_amount'=>'2880.00','payment_collected'=>'1','payment_method'=>'qr_code'],2880.0), 'only after PayMongo verifies');
echo "PASS: QR pending until verification and rejection of manually claimed QR payments.\n";
