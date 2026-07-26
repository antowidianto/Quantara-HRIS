import 'package:flutter_test/flutter_test.dart';
import 'package:hris_mobile/features/common/feature_widgets.dart';

void main() {
  test('titleFromMap selects the first meaningful business title', () {
    expect(
      titleFromMap({'id': 7, 'subject': 'Laptop tidak menyala'}),
      'Laptop tidak menyala',
    );
    expect(titleFromMap({'id': 7}), 'Item #7');
  });

  test('displayValue handles null, booleans, and collections', () {
    expect(displayValue(null), '—');
    expect(displayValue(true), 'Ya');
    expect(displayValue([1, 2]), '2 item');
  });
}
