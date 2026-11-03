<?php
/** Fecha a casca das páginas de fora do app (auth/_casca.php): o <main>, os scripts e o <html>. */
$jsCasca = array_filter(array_merge(['/assets/js/app.js'], (array) ($casca['js'] ?? [])), static fn(string $p): bool => is_file(__DIR__ . '/../../../public' . $p));
?>
</main>
<?php if (!empty($comVitrine)): ?>
</div>
<?php endif; ?>
<?php foreach ($jsCasca as $j): ?>
<script src="<?= e(asset($j)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
