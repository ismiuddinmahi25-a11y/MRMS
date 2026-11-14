<?php
declare(strict_types=1);

$required_role = 'doctor';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

$conn->set_charset('utf8mb4');

$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT id FROM doctors
     WHERE user_id = ? AND verification_status = 'approved'
     LIMIT 1"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$doctor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doctor) {
    exit('Approved doctor profile not found.');
}

$doctorId = (int)$doctor['id'];

$stmt = $conn->prepare(
    "SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.reason,
        a.status,
        u.full_name AS patient_name,
        p.phone
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     INNER JOIN users u ON u.id = p.user_id
     WHERE a.doctor_id = ?
     ORDER BY a.appointment_date DESC,
              a.appointment_time DESC"
);

$stmt->bind_param("i", $doctorId);
$stmt->execute();
$result = $stmt->get_result();

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statusClass($status): string {
    return match($status) {
        'confirmed' => 'confirmed',
        'pending' => 'pending',
        'rejected' => 'rejected',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        default => 'pending'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Appointments | MRMS Doctor</title>
<style>
*{box-sizing:border-box}
body{
 margin:0;padding:25px;
 background:#f4f7fb;
 font-family:Arial,sans-serif;color:#1e293b
}
.container{max-width:1100px;margin:auto}
.header{
 display:flex;justify-content:space-between;
 align-items:center;flex-wrap:wrap;gap:12px;
 margin-bottom:22px
}
h1{font-size:25px;margin:0 0 6px}
p{color:#64748b}
.back{
 text-decoration:none;background:#e2e8f0;
 padding:10px 16px;border-radius:8px;color:#1e293b
}
.card{
 background:white;border-radius:12px;padding:20px;
 box-shadow:0 3px 15px #0000000a;
 overflow-x:auto
}
table{width:100%;border-collapse:collapse;min-width:700px}
th,td{
 padding:14px;text-align:left;
 border-bottom:1px solid #e8edf3;font-size:14px
}
th{color:#64748b;background:#f8fafc}
.badge{
 display:inline-block;padding:6px 10px;
 border-radius:20px;font-size:12px;
 text-transform:capitalize
}
.pending{background:#fff4d6;color:#946200}
.confirmed{background:#dcfce7;color:#166534}
.rejected,.cancelled{background:#fee2e2;color:#991b1b}
.completed{background:#dbeafe;color:#1d4ed8}
.view{
 display:inline-block;padding:8px 13px;
 background:#2563eb;color:white;
 border-radius:7px;text-decoration:none
}
.empty{text-align:center;padding:35px;color:#64748b}
@media(max-width:600px){
 body{padding:14px}
 h1{font-size:21px}
 .card{padding:12px}
}
</style>
</head>
<body>
<div class="container">

<div class="header">
 <div>
  <h1>Appointments</h1>
  <p>Manage your patient appointments.</p>
 </div>
 <a class="back" href="dashboard.php">← Dashboard</a>
</div>

<div class="card">
<?php if ($result->num_rows === 0): ?>
 <div class="empty">No appointments found.</div>
<?php else: ?>
<table>
<thead>
<tr>
 <th>Patient</th>
 <th>Phone</th>
 <th>Date</th>
 <th>Time</th>
 <th>Status</th>
 <th>Action</th>
</tr>
</thead>
<tbody>
<?php while ($row = $result->fetch_assoc()): ?>
<tr>
 <td><?= e($row['patient_name']) ?></td>
 <td><?= e($row['phone']) ?></td>
 <td><?= e($row['appointment_date']) ?></td>
 <td><?= e(date('h:i A', strtotime($row['appointment_time']))) ?></td>
 <td>
  <span class="badge <?= e(statusClass($row['status'])) ?>">
   <?= e($row['status']) ?>
  </span>
 </td>
 <td>
  <a class="view"
     href="appointment_view.php?id=<?= (int)$row['id'] ?>">
     View
  </a>
 </td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
<?php endif; ?>
</div>

</div>
</body>
</html>
<?php $stmt->close(); ?>
