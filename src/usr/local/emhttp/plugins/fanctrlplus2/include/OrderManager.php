<?php
// Fan blocks are one ordered list. The page lays them out side by side and
// wraps to the next row when it runs out of width, so an arrangement is just
// the order, stored as order<n>="file.cfg".
//
// Two older shapes are still read: col<column>_<position> from when blocks were
// arranged in columns, and left<n>/right<n> from when there were exactly two.
// Both are flattened the way they were read on screen -- along each row, then
// down -- and are migrated the next time an order is saved.
class OrderManager {
  private static string $order_file = "/boot/config/plugins/fanctrlplus2/order.cfg";
  private static ?string $order_file_override = null;

  // Test seam: point the manager at another order file.
  public static function useOrderFile(?string $path): void {
    self::$order_file_override = $path;
  }

  private static function orderFile(): string {
    return self::$order_file_override ?? self::$order_file;
  }

  public static function readOrder(): array {
    $file = self::orderFile();
    if (!is_file($file)) return [];

    $list = [];
    $columns = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      if (preg_match('/^order(\d+)\s*=\s*"?(.*?)"?$/', $line, $m)) {
        $list[(int)$m[1]] = $m[2];
      } elseif (preg_match('/^col(\d+)_(\d+)\s*=\s*"?(.*?)"?$/', $line, $m)) {
        $columns[(int)$m[2]][(int)$m[1]] = $m[3];
      } elseif (preg_match('/^(left|right)(\d+)\s*=\s*"?(.*?)"?$/', $line, $m)) {
        $columns[(int)$m[2]][$m[1] === 'left' ? 0 : 1] = $m[3];
      }
    }

    if ($list) {
      ksort($list);
      return array_values($list);
    }

    // Keyed by row then column above, so reading row by row gives the order the
    // blocks appeared in. A column that ran out early simply contributes
    // nothing to the later rows.
    ksort($columns);
    $flattened = [];
    foreach ($columns as $row) {
      ksort($row);
      foreach ($row as $cfg) $flattened[] = $cfg;
    }
    return $flattened;
  }

  public static function writeOrder(array $files): bool {
    $lines = [];
    foreach (array_values($files) as $i => $cfg) {
      $lines[] = 'order' . $i . '="' . $cfg . '"';
    }

    $content = $lines ? implode("\n", $lines) . "\n" : "";
    return file_put_contents(self::orderFile(), $content) !== false;
  }

  public static function remove(string $filename): bool {
    return self::writeOrder(array_values(array_filter(
      self::readOrder(),
      fn($f) => $f !== $filename
    )));
  }

  // Renames in place, without reordering or migrating: a config can be renamed
  // by the save handler before the order itself is rewritten.
  public static function replaceFileName($old_file, $new_file) {
    $file = self::orderFile();
    if (!is_file($file)) return;

    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      if (preg_match('/^(order\d+|col\d+_\d+|left\d+|right\d+)="([^"]*)"/', $line, $m)) {
        $value = $m[2] === $old_file ? $new_file : $m[2];
        $out[] = "{$m[1]}=\"{$value}\"";
      } else {
        $out[] = $line;
      }
    }
    file_put_contents($file, implode("\n", $out) . "\n");
  }
}
