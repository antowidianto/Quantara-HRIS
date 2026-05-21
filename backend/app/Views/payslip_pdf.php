<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Slip Gaji <?= esc($ps['slip_number']) ?></title>
<style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; }
  h1 { font-size: 16px; margin: 0; }
  h2 { font-size: 13px; margin: 0; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { border: 1px solid #e5e7eb; padding: 6px 8px; }
  th { background: #f3f4f6; text-align: left; }
  td.amount, th.amount { text-align: right; }
  .header { display: table; width: 100%; }
  .header > div { display: table-cell; vertical-align: top; }
  .meta td { border: none; padding: 2px 6px; }
  .total { font-weight: bold; background: #f9fafb; }
  .footer { margin-top: 30px; font-size: 10px; color: #6b7280; }
</style>
</head>
<body>

<div class="header">
  <div>
    <h1><?= esc($company['name']) ?></h1>
    <div><?= esc($company['address'] ?? '') ?></div>
    <?php if (! empty($company['npwp'])): ?><div>NPWP: <?= esc($company['npwp']) ?></div><?php endif; ?>
  </div>
  <div style="text-align:right;">
    <h2>SLIP GAJI</h2>
    <div>No: <?= esc($ps['slip_number']) ?></div>
    <div>Periode: <?= esc($period['name']) ?></div>
  </div>
</div>

<hr style="border-color:#e5e7eb;">

<table class="meta">
  <tr><td><strong>NIP</strong></td><td><?= esc($ps['employee_number']) ?></td>
      <td><strong>Status PTKP</strong></td><td><?= esc($ps['ptkp_status'] ?? '-') ?></td></tr>
  <tr><td><strong>Nama</strong></td><td><?= esc($ps['employee_name']) ?></td>
      <td><strong>Hari Kerja</strong></td><td><?= esc($ps['work_days']) ?> hari</td></tr>
  <tr><td><strong>Departemen</strong></td><td><?= esc($ps['department_name'] ?? '-') ?></td>
      <td><strong>Hadir</strong></td><td><?= esc($ps['present_days']) ?> hari</td></tr>
</table>

<table>
  <thead>
    <tr><th colspan="2">Pendapatan</th><th class="amount">Jumlah (Rp)</th></tr>
  </thead>
  <tbody>
    <?php $totalE = 0; foreach ($details as $d): if ($d['type'] !== 'EARNING') continue; $totalE += $d['amount']; ?>
      <tr>
        <td colspan="2"><?= esc($d['name']) ?></td>
        <td class="amount"><?= number_format((float) $d['amount'], 0, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
    <tr class="total"><td colspan="2">Total Pendapatan</td><td class="amount"><?= number_format($totalE, 0, ',', '.') ?></td></tr>
  </tbody>
</table>

<table>
  <thead>
    <tr><th colspan="2">Potongan</th><th class="amount">Jumlah (Rp)</th></tr>
  </thead>
  <tbody>
    <?php $totalD = 0; foreach ($details as $d): if ($d['type'] !== 'DEDUCTION') continue; $totalD += $d['amount']; ?>
      <tr>
        <td colspan="2"><?= esc($d['name']) ?></td>
        <td class="amount"><?= number_format((float) $d['amount'], 0, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
    <tr class="total"><td colspan="2">Total Potongan</td><td class="amount"><?= number_format($totalD, 0, ',', '.') ?></td></tr>
  </tbody>
</table>

<table>
  <tr class="total">
    <td><strong>TAKE HOME PAY</strong></td>
    <td class="amount"><strong>Rp <?= number_format((float) $ps['take_home_pay'], 0, ',', '.') ?></strong></td>
  </tr>
</table>

<div class="footer">
  Slip ini dihasilkan otomatis oleh sistem HRIS pada <?= date('d M Y H:i') ?> dan tidak memerlukan tanda tangan basah.
  Untuk pertanyaan, hubungi HR.
</div>

</body>
</html>