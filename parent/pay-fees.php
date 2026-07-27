<?php
$pageTitle = "Pay Application Fee";
require_once('../includes/db_connect.php');
include('../includes/portal_header.php');
include('../includes/portal_sidebar.php');

$app_id = (int)($_GET['app_id'] ?? $_POST['app_id'] ?? 0);
if (!$app_id) {
    header("Location: dashboard.php"); exit();
}

$fullApp = $conn->query("SELECT * FROM applications WHERE id='$app_id' AND parent_id='$parent_id'")->fetch_assoc();
if (!$fullApp) {
    header("Location: dashboard.php"); exit();
}

// Auto-migrate fees table
$cols = $conn->query("SHOW COLUMNS FROM fees LIKE 'fee_type'");
if ($cols->num_rows === 0) {
    $conn->query("ALTER TABLE fees ADD COLUMN fee_type VARCHAR(20) DEFAULT 'monthly' AFTER application_id");
    $conn->query("ALTER TABLE fees ADD COLUMN razorpay_order_id VARCHAR(100) AFTER amount");
    $conn->query("ALTER TABLE fees ADD COLUMN razorpay_payment_id VARCHAR(100) AFTER razorpay_order_id");
    $conn->query("ALTER TABLE fees ADD COLUMN razorpay_signature VARCHAR(255) AFTER razorpay_payment_id");
    $conn->query("ALTER TABLE fees MODIFY COLUMN month VARCHAR(20) NULL");
    $conn->query("ALTER TABLE fees MODIFY COLUMN year INT NULL");
}

$error   = '';
$success = '';
$fee_amount = APPLICATION_FEE;

// Check if already paid
$paidCheck = $conn->query("SELECT id, razorpay_payment_id FROM fees WHERE application_id='$app_id' AND fee_type='application' AND status='Paid'");
$alreadyPaid = $paidCheck->num_rows > 0;
$payment_id = $alreadyPaid ? $paidCheck->fetch_assoc()['razorpay_payment_id'] : '';

// Handle payment verification (Razorpay callback)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['razorpay_payment_id'])) {
    $rzp_order_id   = $_POST['razorpay_order_id'];
    $rzp_payment_id = $_POST['razorpay_payment_id'];
    $rzp_signature  = $_POST['razorpay_signature'];

    $expected = hash_hmac('sha256', "$rzp_order_id|$rzp_payment_id", RAZORPAY_KEY_SECRET);

    if ($expected === $rzp_signature) {
        $insert = $conn->query("INSERT INTO fees (application_id, fee_type, amount, status, razorpay_order_id, razorpay_payment_id, razorpay_signature, paid_at)
                                VALUES ('$app_id','application','$fee_amount','Paid','$rzp_order_id','$rzp_payment_id','$rzp_signature',NOW())");
        if ($insert) {
            header("Location: application-summary.php?app_id=$app_id"); exit();
        } else {
            $error = "Could not save payment record. Please contact the school.";
        }
    } else {
        $error = "Payment verification failed. Please contact the school.";
    }
}

// Create Razorpay Order (if not already paid)
$rzp_order_id = '';
$rzp_order_created = false;
if (!$alreadyPaid && !$error) {
    $receipt = 'app_' . $app_id . '_' . time();
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.razorpay.com/v1/orders');
    curl_setopt($ch, CURLOPT_USERPWD, RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'amount'   => $fee_amount * 100, // paise
        'currency' => 'INR',
        'receipt'  => $receipt,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $order = json_decode($response, true);
        $rzp_order_id = $order['id'] ?? '';
        $rzp_order_created = true;
    } else {
        $error = "Could not connect to payment gateway. Please try again later.";
    }
}
?>

<style>
.pay-wrap { max-width: 580px; margin: 0 auto; }
.pay-card { background: #fff; border-radius: var(--border-radius); box-shadow: var(--shadow); overflow: hidden; margin-bottom: 1.5rem; }
.pay-card .head { background: var(--primary-color); color: #fff; padding: 0.85rem 1.5rem; font-weight: 700; font-size: 1.05rem; }
.pay-card .body { padding: 1.5rem; }
.pay-info-row { display: flex; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid #f0f0f0; font-size: 0.93rem; }
.pay-info-row .lbl { color: var(--text-light); }
.pay-info-row .val { font-weight: 600; color: var(--text-color); }
.pay-amount { text-align: center; padding: 1.5rem 0; }
.pay-amount .rupee { font-size: 2.5rem; font-weight: 800; color: var(--primary-color); }
.pay-amount .label { color: var(--text-light); font-size: 0.85rem; }
</style>

<div class="portal-header">
    <div class="portal-header-title">
        <h2><i class="fas fa-credit-card"></i> &nbsp;Application Fee Payment</h2>
        <p>Complete the payment to confirm your application for admission.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<?php if ($alreadyPaid): ?>
    <div class="alert alert-success" style="margin-bottom:1.5rem;">
        <i class="fas fa-check-circle"></i> Application fee already paid (Payment ID: <?php echo htmlspecialchars($payment_id); ?>).
        <a href="application-summary.php?app_id=<?php echo $app_id; ?>" style="font-weight:600;text-decoration:underline;">View Application Summary</a>
    </div>
<?php endif; ?>

<div class="pay-wrap">
    <div class="pay-card">
        <div class="head"><i class="fas fa-file-invoice"></i> Application Summary</div>
        <div class="body">
            <div class="pay-info-row"><span class="lbl">Student Name</span><span class="val"><?php echo htmlspecialchars($fullApp['student_name']); ?></span></div>
            <div class="pay-info-row"><span class="lbl">Class</span><span class="val"><?php echo htmlspecialchars($fullApp['class_sought']); ?></span></div>
            <div class="pay-info-row"><span class="lbl">Application ID</span><span class="val">SIBA-2026-<?php echo str_pad($app_id, 4, '0', STR_PAD_LEFT); ?></span></div>
            <div class="pay-info-row"><span class="lbl">Father's Name</span><span class="val"><?php echo htmlspecialchars($fullApp['father_name']); ?></span></div>
            <div class="pay-info-row"><span class="lbl">Mother's Name</span><span class="val"><?php echo htmlspecialchars($fullApp['mother_name']); ?></span></div>

            <div class="pay-amount">
                <div class="label">Application Fee</div>
                <div class="rupee">₹<?php echo number_format($fee_amount); ?></div>
            </div>

            <?php if (!$alreadyPaid && $rzp_order_created): ?>
                <button id="rzpBtn" class="btn btn-primary btn-lg" style="width:100%;font-size:1.1rem;">
                    <i class="fas fa-lock"></i> Pay ₹<?php echo number_format($fee_amount); ?> Now
                </button>

                <p style="text-align:center;margin-top:1rem;font-size:0.8rem;color:var(--text-light);">
                    <i class="fas fa-shield-alt"></i> Secured by Razorpay
                </p>

                <form method="POST" id="rzpForm">
                    <input type="hidden" name="app_id" value="<?php echo $app_id; ?>">
                    <input type="hidden" name="razorpay_order_id" id="rzp_order_id" value="">
                    <input type="hidden" name="razorpay_payment_id" id="rzp_payment_id" value="">
                    <input type="hidden" name="razorpay_signature" id="rzp_signature" value="">
                </form>

                <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                <script>
                document.getElementById('rzpBtn').addEventListener('click', function(e){
                    var options = {
                        key: "<?php echo RAZORPAY_KEY_ID; ?>",
                        amount: "<?php echo $fee_amount * 100; ?>",
                        currency: "INR",
                        name: "SIBA Public School",
                        description: "Application Fee (<?php echo htmlspecialchars($fullApp['student_name']); ?>)",
                        order_id: "<?php echo $rzp_order_id; ?>",
                        handler: function(response){
                            document.getElementById('rzp_order_id').value   = response.razorpay_order_id;
                            document.getElementById('rzp_payment_id').value = response.razorpay_payment_id;
                            document.getElementById('rzp_signature').value  = response.razorpay_signature;
                            document.getElementById('rzpForm').submit();
                        },
                        modal: {
                            ondismiss: function(){
                                // user closed the modal, do nothing
                            }
                        },
                        prefill: {
                            name: "<?php echo htmlspecialchars($fullApp['father_name']); ?>",
                            email: "<?php echo htmlspecialchars($fullApp['email'] ?? ''); ?>",
                            contact: "<?php echo htmlspecialchars($fullApp['contact_no'] ?? ''); ?>"
                        },
                        theme: { color: "#4b5563" }
                    };
                    var rzp = new Razorpay(options);
                    rzp.open();
                });
                </script>
            <?php elseif (!$alreadyPaid): ?>
                <p style="text-align:center;color:var(--text-light);">Unable to initiate payment. Please try again later.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

</div></div>
<?php include('../includes/portal_footer.php'); ?>
