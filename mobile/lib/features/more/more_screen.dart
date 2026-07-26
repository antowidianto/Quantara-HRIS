import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:local_auth/local_auth.dart';

import '../../core/auth/session_controller.dart';
import '../../core/network/api_client.dart';
import '../../core/offline/outbox.dart';
import '../common/api_collection_screen.dart';
import '../common/feature_widgets.dart';

class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key});

  static final _modules = <_Module>[
    _Module(
      'Lembur',
      'Ajukan dan lihat riwayat lembur',
      Icons.more_time,
      '/overtimes?my=1',
      create: const QuickCreateSpec(
        title: 'Ajukan lembur',
        endpoint: '/overtimes',
        fields: [
          QuickCreateField('work_date', 'Tanggal (YYYY-MM-DD)'),
          QuickCreateField('start_time', 'Mulai (HH:MM)'),
          QuickCreateField('end_time', 'Selesai (HH:MM)'),
          QuickCreateField('reason', 'Alasan', multiline: true),
        ],
      ),
    ),
    _Module(
      'Reimbursement',
      'Klaim biaya operasional',
      Icons.receipt_long_outlined,
      '/reimbursements?my=1',
      create: const QuickCreateSpec(
        title: 'Ajukan reimbursement',
        endpoint: '/reimbursements',
        fields: [
          QuickCreateField('vendor', 'Vendor'),
          QuickCreateField('total_amount', 'Total', numeric: true),
          QuickCreateField('receipt_date', 'Tanggal struk (YYYY-MM-DD)'),
          QuickCreateField('category', 'Kategori'),
          QuickCreateField(
            'description',
            'Keterangan',
            multiline: true,
            required: false,
          ),
        ],
      ),
    ),
    _Module(
      'Perjalanan dinas',
      'SPPD dan status perjalanan',
      Icons.flight_takeoff,
      '/business-trips?my=1',
      create: const QuickCreateSpec(
        title: 'Ajukan perjalanan dinas',
        endpoint: '/business-trips',
        fields: [
          QuickCreateField('destination', 'Tujuan'),
          QuickCreateField('start_date', 'Mulai (YYYY-MM-DD)'),
          QuickCreateField('end_date', 'Selesai (YYYY-MM-DD)'),
          QuickCreateField('purpose', 'Keperluan', multiline: true),
          QuickCreateField(
            'perdiem_amount',
            'Per diem',
            numeric: true,
            required: false,
          ),
        ],
      ),
    ),
    _Module(
      'Helpdesk',
      'Ticket IT, HR, Finance, dan GA',
      Icons.support_agent,
      '/helpdesk/tickets?my=1',
      create: const QuickCreateSpec(
        title: 'Buat ticket',
        endpoint: '/helpdesk/tickets',
        fields: [
          QuickCreateField('subject', 'Subjek'),
          QuickCreateField('category', 'Kategori'),
          QuickCreateField('priority', 'Prioritas (LOW/NORMAL/HIGH/URGENT)'),
          QuickCreateField('description', 'Deskripsi', multiline: true),
        ],
      ),
    ),
    const _Module(
      'Notifikasi',
      'Pengumuman dan alert',
      Icons.notifications_outlined,
      '/notifications',
    ),
    const _Module(
      'Performance',
      'Goals dan review',
      Icons.track_changes_outlined,
      '/performance/my',
    ),
    const _Module(
      'Training',
      'Katalog pelatihan',
      Icons.school_outlined,
      '/trainings?status=POSTED',
    ),
    const _Module(
      'Tasks',
      'Onboarding dan offboarding',
      Icons.task_alt_outlined,
      '/employee-tasks?per_page=100',
    ),
    const _Module(
      'Profil',
      'Identitas akun aktif',
      Icons.person_outline,
      '/auth/me',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        const FeatureHeader(
          title: 'Lainnya',
          subtitle: 'Layanan mandiri dan pengaturan akun.',
        ),
        Expanded(
          child: GridView.builder(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 2,
              childAspectRatio: 1.08,
              crossAxisSpacing: 12,
              mainAxisSpacing: 12,
            ),
            itemCount: _modules.length + 1,
            itemBuilder: (context, index) {
              if (index == _modules.length) {
                return _MenuCard(
                  title: 'Pengaturan',
                  subtitle: 'Password, biometrik, dan sesi',
                  icon: Icons.settings_outlined,
                  onTap: () =>
                      _open(context, 'Pengaturan', const SettingsScreen()),
                );
              }
              final module = _modules[index];
              return _MenuCard(
                title: module.title,
                subtitle: module.subtitle,
                icon: module.icon,
                onTap: () => _open(
                  context,
                  module.title,
                  ApiCollectionScreen(
                    title: module.title,
                    subtitle: module.subtitle,
                    endpoint: module.endpoint,
                    icon: module.icon,
                    create: module.create,
                  ),
                ),
              );
            },
          ),
        ),
      ],
    );
  }

  void _open(BuildContext context, String title, Widget child) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (context) => Scaffold(
          appBar: AppBar(title: Text(title)),
          body: child,
        ),
      ),
    );
  }
}

class _Module {
  const _Module(
    this.title,
    this.subtitle,
    this.icon,
    this.endpoint, {
    this.create,
  });
  final String title;
  final String subtitle;
  final IconData icon;
  final String endpoint;
  final QuickCreateSpec? create;
}

class _MenuCard extends StatelessWidget {
  const _MenuCard({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.onTap,
  });
  final String title;
  final String subtitle;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: EdgeInsets.zero,
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(child: Icon(icon)),
              const Spacer(),
              Text(
                title,
                style: Theme.of(
                  context,
                ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 4),
              Text(
                subtitle,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class SettingsScreen extends ConsumerStatefulWidget {
  const SettingsScreen({super.key});

  @override
  ConsumerState<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends ConsumerState<SettingsScreen> {
  late Future<int> _outboxCount;

  @override
  void initState() {
    super.initState();
    _outboxCount = _loadOutboxCount();
  }

  Future<int> _loadOutboxCount() async =>
      (await ref.read(outboxProvider).list()).length;

  Future<void> _changePassword() async {
    final oldPassword = TextEditingController();
    final newPassword = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Ubah password'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: oldPassword,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Password saat ini'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: newPassword,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Password baru'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Simpan'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    try {
      await ref
          .read(apiClientProvider)
          .post<dynamic>(
            '/auth/change-password',
            data: {
              'old_password': oldPassword.text,
              'new_password': newPassword.text,
            },
          );
      _message('Password berhasil diubah.');
    } on Object catch (error) {
      _message(error.toString());
    } finally {
      oldPassword.dispose();
      newPassword.dispose();
    }
  }

  Future<void> _testBiometric() async {
    try {
      final authenticated = await LocalAuthentication().authenticate(
        localizedReason: 'Verifikasi akses Quantara HRIS',
        biometricOnly: true,
      );
      _message(authenticated ? 'Biometrik berhasil.' : 'Biometrik dibatalkan.');
    } on Object catch (error) {
      _message(error.toString());
    }
  }

  Future<void> _sweep() async {
    final sent = await ref.read(outboxProvider).sweep();
    setState(() => _outboxCount = _loadOutboxCount());
    _message('$sent item antrean berhasil dikirim.');
  }

  void _message(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(sessionProvider).user;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: ListTile(
            leading: const CircleAvatar(child: Icon(Icons.person)),
            title: Text(user?.fullName ?? 'User'),
            subtitle: Text(user?.email ?? ''),
          ),
        ),
        const SizedBox(height: 8),
        Card(
          child: Column(
            children: [
              ListTile(
                leading: const Icon(Icons.password),
                title: const Text('Ubah password'),
                trailing: const Icon(Icons.chevron_right),
                onTap: _changePassword,
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.fingerprint),
                title: const Text('Uji biometrik'),
                trailing: const Icon(Icons.chevron_right),
                onTap: _testBiometric,
              ),
              const Divider(height: 1),
              FutureBuilder<int>(
                future: _outboxCount,
                builder: (context, snapshot) => ListTile(
                  leading: const Icon(Icons.sync),
                  title: const Text('Antrean offline'),
                  subtitle: Text('${snapshot.data ?? 0} item menunggu'),
                  trailing: TextButton(
                    onPressed: _sweep,
                    child: const Text('Kirim'),
                  ),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        OutlinedButton.icon(
          onPressed: () async {
            Navigator.of(context).popUntil((route) => route.isFirst);
            await ref.read(sessionProvider.notifier).signOut();
          },
          icon: const Icon(Icons.logout),
          label: const Text('Keluar'),
        ),
      ],
    );
  }
}
