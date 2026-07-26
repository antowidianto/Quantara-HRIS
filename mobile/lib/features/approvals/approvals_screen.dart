import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../common/feature_widgets.dart';

class ApprovalsScreen extends ConsumerStatefulWidget {
  const ApprovalsScreen({super.key});

  @override
  ConsumerState<ApprovalsScreen> createState() => _ApprovalsScreenState();
}

class _ApprovalsScreenState extends ConsumerState<ApprovalsScreen> {
  late Future<List<Map<String, dynamic>>> _future;
  int? _busyId;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    final raw = await ref
        .read(apiClientProvider)
        .get<dynamic>('/approval-requests/inbox');
    if (raw is! List) return [];
    return raw
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList();
  }

  Future<void> _refresh() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _decide(int id, bool approve) async {
    final note = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(approve ? 'Setujui permintaan?' : 'Tolak permintaan?'),
        content: TextField(
          controller: note,
          maxLines: 3,
          decoration: const InputDecoration(labelText: 'Catatan (opsional)'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(approve ? 'Setujui' : 'Tolak'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    setState(() => _busyId = id);
    try {
      await ref
          .read(apiClientProvider)
          .post<dynamic>(
            '/approval-requests/$id/${approve ? 'approve' : 'reject'}',
            data: {'note': note.text.trim()},
          );
      await _refresh();
    } on Object catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error.toString())));
      }
    } finally {
      if (mounted) setState(() => _busyId = null);
      note.dispose();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        FeatureHeader(
          title: 'Inbox Approval',
          subtitle: 'Keputusan tetap divalidasi backend dengan maker-checker.',
          action: IconButton(
            onPressed: _refresh,
            icon: const Icon(Icons.refresh),
          ),
        ),
        Expanded(
          child: FutureBuilder<List<Map<String, dynamic>>>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return EmptyState(
                  title: 'Inbox gagal dimuat',
                  message: snapshot.error.toString(),
                  onRefresh: _refresh,
                );
              }
              final items = snapshot.data ?? const [];
              if (items.isEmpty) {
                return EmptyState(
                  title: 'Tidak ada approval pending',
                  onRefresh: _refresh,
                );
              }
              return RefreshIndicator(
                onRefresh: _refresh,
                child: ListView.builder(
                  itemCount: items.length,
                  itemBuilder: (context, index) {
                    final item = items[index];
                    final id = (item['id'] as num).toInt();
                    return Card(
                      margin: const EdgeInsets.fromLTRB(16, 6, 16, 6),
                      child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              item['subject']?.toString() ?? 'Permintaan',
                              style: Theme.of(context).textTheme.titleMedium
                                  ?.copyWith(fontWeight: FontWeight.w700),
                            ),
                            const SizedBox(height: 6),
                            Text(
                              '${item['module'] ?? 'Modul'} • ${item['requester'] ?? 'Pemohon'}',
                            ),
                            const SizedBox(height: 14),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.end,
                              children: [
                                OutlinedButton(
                                  onPressed: _busyId == null
                                      ? () => _decide(id, false)
                                      : null,
                                  child: const Text('Tolak'),
                                ),
                                const SizedBox(width: 10),
                                FilledButton(
                                  onPressed: _busyId == null
                                      ? () => _decide(id, true)
                                      : null,
                                  child: _busyId == id
                                      ? const SizedBox.square(
                                          dimension: 18,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                          ),
                                        )
                                      : const Text('Setujui'),
                                ),
                              ],
                            ),
                          ],
                        ),
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
