import 'package:flutter/material.dart';

String displayValue(Object? value) {
  if (value == null) return '—';
  if (value is bool) return value ? 'Ya' : 'Tidak';
  if (value is List) return '${value.length} item';
  if (value is Map) return '${value.length} field';
  return value.toString();
}

String titleFromMap(Map<String, dynamic> item) {
  for (final key in const [
    'title',
    'subject',
    'name',
    'destination',
    'vendor',
    'leave_type',
    'period_label',
    'employee_name',
    'full_name',
    'email',
  ]) {
    final value = item[key];
    if (value != null && value.toString().trim().isNotEmpty) {
      return value.toString();
    }
  }
  final id = item['id'];
  return id == null ? 'Detail' : 'Item #$id';
}

class FeatureHeader extends StatelessWidget {
  const FeatureHeader({
    required this.title,
    this.subtitle,
    this.action,
    super.key,
  });
  final String title;
  final String? subtitle;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                // ignore: use_null_aware_elements
                if (subtitle != null) ...[
                  const SizedBox(height: 4),
                  Text(subtitle!, style: Theme.of(context).textTheme.bodySmall),
                ],
              ],
            ),
          ),
          // ignore: use_null_aware_elements
          if (action != null) action!,
        ],
      ),
    );
  }
}

class FeatureCard extends StatelessWidget {
  const FeatureCard({
    required this.title,
    required this.fields,
    this.leading,
    this.trailing,
    this.onTap,
    super.key,
  });

  final String title;
  final Map<String, Object?> fields;
  final Widget? leading;
  final Widget? trailing;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final visible = fields.entries
        .where(
          (entry) => entry.value != null && entry.value.toString().isNotEmpty,
        )
        .take(4)
        .toList();
    return Card(
      margin: const EdgeInsets.fromLTRB(16, 6, 16, 6),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (leading != null) ...[leading!, const SizedBox(width: 12)],
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    for (final entry in visible) ...[
                      const SizedBox(height: 5),
                      Text(
                        '${entry.key}: ${displayValue(entry.value)}',
                        style: Theme.of(context).textTheme.bodySmall,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ],
                ),
              ),
              // ignore: use_null_aware_elements
              if (trailing != null) trailing!,
            ],
          ),
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({
    required this.title,
    this.message,
    this.onRefresh,
    super.key,
  });
  final String title;
  final String? message;
  final Future<void> Function()? onRefresh;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.inbox_outlined,
              size: 52,
              color: Theme.of(context).colorScheme.outline,
            ),
            const SizedBox(height: 12),
            Text(title, style: Theme.of(context).textTheme.titleMedium),
            if (message != null) ...[
              const SizedBox(height: 6),
              Text(message!, textAlign: TextAlign.center),
            ],
            if (onRefresh != null) ...[
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: onRefresh,
                icon: const Icon(Icons.refresh),
                label: const Text('Muat ulang'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
