import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/network/api_client.dart';
import '../common/feature_widgets.dart';

class PayslipScreen extends ConsumerStatefulWidget {
  const PayslipScreen({super.key});

  @override
  ConsumerState<PayslipScreen> createState() => _PayslipScreenState();
}

class _PayslipScreenState extends ConsumerState<PayslipScreen> {
  late Future<List<Map<String, dynamic>>> _future;
  final _currency = NumberFormat.currency(
    locale: 'id_ID',
    symbol: 'Rp ',
    decimalDigits: 0,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    final raw = await ref.read(apiClientProvider).get<dynamic>('/payslips/my');
    if (raw is! List) return [];
    return raw
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList();
  }

  Future<void> _detail(int id) async {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => _PayslipDetail(id: id, currency: _currency),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        const FeatureHeader(
          title: 'Slip Gaji',
          subtitle: 'Rincian penerimaan dan potongan hanya-baca.',
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
                  title: 'Slip gaji gagal dimuat',
                  message: snapshot.error.toString(),
                );
              }
              final items = snapshot.data ?? const [];
              if (items.isEmpty) {
                return const EmptyState(title: 'Belum ada slip gaji');
              }
              return ListView.builder(
                itemCount: items.length,
                itemBuilder: (context, index) {
                  final item = items[index];
                  final id = (item['id'] as num).toInt();
                  return FeatureCard(
                    title: item['period_label']?.toString() ?? 'Periode gaji',
                    fields: {
                      'Bruto': _currency.format(item['gross_amount'] ?? 0),
                      'Take-home': _currency.format(item['net_amount'] ?? 0),
                      'Dipublikasi': item['published_at'],
                    },
                    leading: const CircleAvatar(
                      child: Icon(Icons.payments_outlined),
                    ),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => _detail(id),
                  );
                },
              );
            },
          ),
        ),
      ],
    );
  }
}

class _PayslipDetail extends ConsumerWidget {
  const _PayslipDetail({required this.id, required this.currency});
  final int id;
  final NumberFormat currency;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return FutureBuilder<Map<String, dynamic>>(
      future: ref.read(apiClientProvider).get('/payslips/$id'),
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const SizedBox(
            height: 300,
            child: Center(child: CircularProgressIndicator()),
          );
        }
        if (snapshot.hasError) {
          return SizedBox(
            height: 300,
            child: EmptyState(
              title: 'Detail gagal dimuat',
              message: snapshot.error.toString(),
            ),
          );
        }
        final data = snapshot.data ?? const {};
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
            child: ListView(
              shrinkWrap: true,
              children: [
                Text(
                  data['period_label']?.toString() ?? 'Detail slip gaji',
                  style: Theme.of(
                    context,
                  ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 16),
                _lines(context, 'Penerimaan', data['earnings']),
                const SizedBox(height: 16),
                _lines(context, 'Potongan', data['deductions']),
                const Divider(height: 28),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Take-home pay'),
                  trailing: Text(
                    currency.format(data['net_amount'] ?? 0),
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _lines(BuildContext context, String title, Object? raw) {
    final lines = raw is List ? raw.whereType<Map>().toList() : const <Map>[];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: Theme.of(
            context,
          ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
        ),
        for (final line in lines)
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            title: Text(line['name']?.toString() ?? 'Komponen'),
            trailing: Text(currency.format(line['amount'] ?? 0)),
          ),
      ],
    );
  }
}
