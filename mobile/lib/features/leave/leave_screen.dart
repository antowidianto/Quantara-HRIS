import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/network/api_client.dart';
import '../common/feature_widgets.dart';

class LeaveScreen extends ConsumerStatefulWidget {
  const LeaveScreen({super.key});

  @override
  ConsumerState<LeaveScreen> createState() => _LeaveScreenState();
}

class _LeaveScreenState extends ConsumerState<LeaveScreen> {
  late Future<List<Map<String, dynamic>>> _requests;

  @override
  void initState() {
    super.initState();
    _requests = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    final raw = await ref.read(apiClientProvider).get<dynamic>('/leaves?my=1');
    if (raw is! List) return [];
    return raw
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList();
  }

  Future<void> _refresh() async {
    setState(() => _requests = _load());
    await _requests;
  }

  Future<void> _create() async {
    final created = await showModalBottomSheet<bool>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => const _LeaveForm(),
    );
    if (created == true) await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        FeatureHeader(
          title: 'Cuti',
          subtitle: 'Saldo, pengajuan, dan riwayat persetujuan.',
          action: FilledButton.icon(
            onPressed: _create,
            icon: const Icon(Icons.add),
            label: const Text('Ajukan'),
          ),
        ),
        Expanded(
          child: FutureBuilder<List<Map<String, dynamic>>>(
            future: _requests,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return EmptyState(
                  title: 'Cuti gagal dimuat',
                  message: snapshot.error.toString(),
                  onRefresh: _refresh,
                );
              }
              final items = snapshot.data ?? const [];
              if (items.isEmpty) {
                return EmptyState(
                  title: 'Belum ada pengajuan cuti',
                  onRefresh: _refresh,
                );
              }
              return RefreshIndicator(
                onRefresh: _refresh,
                child: ListView.builder(
                  itemCount: items.length,
                  itemBuilder: (context, index) {
                    final item = items[index];
                    return FeatureCard(
                      title: item['leave_type']?.toString() ?? 'Cuti',
                      fields: {
                        'Periode':
                            '${item['start_date'] ?? '—'} – ${item['end_date'] ?? '—'}',
                        'Hari': item['days'],
                        'Status': item['status'],
                        'Alasan': item['reason'],
                      },
                      leading: const CircleAvatar(
                        child: Icon(Icons.beach_access_outlined),
                      ),
                    );
                  },
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}

class _LeaveForm extends ConsumerStatefulWidget {
  const _LeaveForm();

  @override
  ConsumerState<_LeaveForm> createState() => _LeaveFormState();
}

class _LeaveFormState extends ConsumerState<_LeaveForm> {
  final _reason = TextEditingController();
  DateTime _start = DateTime.now();
  DateTime _end = DateTime.now();
  int? _typeId;
  late Future<List<Map<String, dynamic>>> _types;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _types = _loadTypes();
  }

  Future<List<Map<String, dynamic>>> _loadTypes() async {
    final raw = await ref
        .read(apiClientProvider)
        .get<dynamic>('/leave-types/for-me');
    if (raw is! List) return [];
    return raw
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList();
  }

  Future<void> _pick(bool start) async {
    final picked = await showDatePicker(
      context: context,
      firstDate: DateTime.now().subtract(const Duration(days: 30)),
      lastDate: DateTime.now().add(const Duration(days: 730)),
      initialDate: start ? _start : _end,
    );
    if (picked == null) return;
    setState(() {
      if (start) {
        _start = picked;
        if (_end.isBefore(_start)) _end = _start;
      } else {
        _end = picked;
      }
    });
  }

  Future<void> _save() async {
    if (_typeId == null || _reason.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Jenis cuti dan alasan wajib diisi.')),
      );
      return;
    }
    setState(() => _saving = true);
    try {
      final format = DateFormat('yyyy-MM-dd');
      await ref
          .read(apiClientProvider)
          .post<dynamic>(
            '/leaves',
            data: {
              'leave_type_id': _typeId,
              'start_date': format.format(_start),
              'end_date': format.format(_end),
              'reason': _reason.text.trim(),
            },
          );
      if (mounted) Navigator.pop(context, true);
    } on Object catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(error.toString())));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final format = DateFormat('dd MMM yyyy');
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          24,
          8,
          24,
          24 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: ListView(
          shrinkWrap: true,
          children: [
            Text(
              'Ajukan cuti',
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 18),
            FutureBuilder<List<Map<String, dynamic>>>(
              future: _types,
              builder: (context, snapshot) {
                final types = snapshot.data ?? const [];
                return DropdownButtonFormField<int>(
                  initialValue: _typeId,
                  decoration: const InputDecoration(labelText: 'Jenis cuti'),
                  items: [
                    for (final type in types)
                      DropdownMenuItem(
                        value: (type['id'] as num).toInt(),
                        child: Text(type['name']?.toString() ?? 'Cuti'),
                      ),
                  ],
                  onChanged: (value) => setState(() => _typeId = value),
                );
              },
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => _pick(true),
                    child: Text('Mulai\n${format.format(_start)}'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => _pick(false),
                    child: Text('Selesai\n${format.format(_end)}'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _reason,
              maxLines: 3,
              decoration: const InputDecoration(labelText: 'Alasan'),
            ),
            const SizedBox(height: 18),
            FilledButton(
              onPressed: _saving ? null : _save,
              child: Text(_saving ? 'Menyimpan…' : 'Kirim pengajuan'),
            ),
          ],
        ),
      ),
    );
  }
}
