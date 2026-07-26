import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import 'feature_widgets.dart';

class ApiCollectionScreen extends ConsumerStatefulWidget {
  const ApiCollectionScreen({
    required this.title,
    required this.endpoint,
    required this.icon,
    this.subtitle,
    this.emptyMessage,
    this.create,
    super.key,
  });

  final String title;
  final String endpoint;
  final IconData icon;
  final String? subtitle;
  final String? emptyMessage;
  final QuickCreateSpec? create;

  @override
  ConsumerState<ApiCollectionScreen> createState() =>
      _ApiCollectionScreenState();
}

class _ApiCollectionScreenState extends ConsumerState<ApiCollectionScreen> {
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    final raw = await ref.read(apiClientProvider).get<dynamic>(widget.endpoint);
    if (raw is List) {
      return raw
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList();
    }
    if (raw is Map) {
      final map = Map<String, dynamic>.from(raw);
      for (final key in const ['data', 'items', 'records']) {
        final list = map[key];
        if (list is List) {
          return list
              .whereType<Map>()
              .map((item) => Map<String, dynamic>.from(item))
              .toList();
        }
      }
      return [map];
    }
    return [];
  }

  Future<void> _refresh() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _create() async {
    final spec = widget.create;
    if (spec == null) return;
    final created = await showModalBottomSheet<bool>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => _QuickCreateSheet(spec: spec),
    );
    if (created == true) await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        FeatureHeader(
          title: widget.title,
          subtitle: widget.subtitle,
          action: IconButton(
            tooltip: widget.create == null ? 'Muat ulang' : 'Tambah',
            onPressed: widget.create == null ? _refresh : _create,
            icon: Icon(widget.create == null ? Icons.refresh : Icons.add),
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
                  title: 'Data gagal dimuat',
                  message: snapshot.error.toString(),
                  onRefresh: _refresh,
                );
              }
              final items = snapshot.data ?? const [];
              if (items.isEmpty) {
                return EmptyState(
                  title: 'Belum ada data',
                  message: widget.emptyMessage,
                  onRefresh: _refresh,
                );
              }
              return RefreshIndicator(
                onRefresh: _refresh,
                child: ListView.builder(
                  padding: const EdgeInsets.only(bottom: 24),
                  itemCount: items.length,
                  itemBuilder: (context, index) {
                    final item = items[index];
                    final fields = Map<String, Object?>.from(item)
                      ..remove('id')
                      ..remove('title')
                      ..remove('subject')
                      ..remove('name');
                    return FeatureCard(
                      title: titleFromMap(item),
                      fields: fields,
                      leading: CircleAvatar(child: Icon(widget.icon, size: 20)),
                      onTap: () => showModalBottomSheet<void>(
                        context: context,
                        showDragHandle: true,
                        isScrollControlled: true,
                        builder: (context) =>
                            _DetailSheet(title: titleFromMap(item), item: item),
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

class QuickCreateField {
  const QuickCreateField(
    this.key,
    this.label, {
    this.numeric = false,
    this.multiline = false,
    this.required = true,
  });
  final String key;
  final String label;
  final bool numeric;
  final bool multiline;
  final bool required;
}

class QuickCreateSpec {
  const QuickCreateSpec({
    required this.title,
    required this.endpoint,
    required this.fields,
  });
  final String title;
  final String endpoint;
  final List<QuickCreateField> fields;
}

class _QuickCreateSheet extends ConsumerStatefulWidget {
  const _QuickCreateSheet({required this.spec});
  final QuickCreateSpec spec;

  @override
  ConsumerState<_QuickCreateSheet> createState() => _QuickCreateSheetState();
}

class _QuickCreateSheetState extends ConsumerState<_QuickCreateSheet> {
  late final Map<String, TextEditingController> _controllers = {
    for (final field in widget.spec.fields) field.key: TextEditingController(),
  };
  bool _saving = false;

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    for (final field in widget.spec.fields) {
      if (field.required && _controllers[field.key]!.text.trim().isEmpty) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('${field.label} wajib diisi.')));
        return;
      }
    }
    setState(() => _saving = true);
    try {
      final data = <String, dynamic>{};
      for (final field in widget.spec.fields) {
        final value = _controllers[field.key]!.text.trim();
        if (value.isEmpty) continue;
        data[field.key] = field.numeric ? num.tryParse(value) ?? value : value;
      }
      await ref
          .read(apiClientProvider)
          .post<dynamic>(widget.spec.endpoint, data: data);
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
              widget.spec.title,
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 18),
            for (final field in widget.spec.fields) ...[
              TextField(
                controller: _controllers[field.key],
                keyboardType: field.numeric
                    ? const TextInputType.numberWithOptions(decimal: true)
                    : field.multiline
                    ? TextInputType.multiline
                    : TextInputType.text,
                maxLines: field.multiline ? 3 : 1,
                decoration: InputDecoration(
                  labelText:
                      '${field.label}${field.required ? '' : ' (opsional)'}',
                ),
              ),
              const SizedBox(height: 12),
            ],
            FilledButton(
              onPressed: _saving ? null : _save,
              child: Text(_saving ? 'Menyimpan…' : 'Simpan'),
            ),
          ],
        ),
      ),
    );
  }
}

class _DetailSheet extends StatelessWidget {
  const _DetailSheet({required this.title, required this.item});
  final String title;
  final Map<String, dynamic> item;

  @override
  Widget build(BuildContext context) {
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
              title,
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 16),
            for (final entry in item.entries)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(
                      width: 130,
                      child: Text(
                        entry.key,
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ),
                    Expanded(child: Text(displayValue(entry.value))),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}
