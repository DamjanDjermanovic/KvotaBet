<?php
// Podnožje stranice: zatvara glavni sadržaj otvoren u zaglavlje.php,
// ispisuje footer i uključuje zajedničke JS skripte. Stranica pre uključivanja
// ovog fajla može postaviti $dodatne_skripte kao niz naziva fajlova iz js/
// koji su joj potrebni pored zajedničkih skripti.
?>
</div>
</main>

<footer class="podnozje mt-auto">
    <div class="container text-center">
        &copy; <?= date('Y') ?> Kladionica — fakultetski projekat

        <?php // Napomena stoji na svakoj strani da nijedan ekran ne ostavi utisak pravog uplatnog mesta ?>
        <div class="napomena">
            Simulacija sa virtuelnim novcem. Nema pravih uplata, platnih kartica
            ni povezivanja sa spoljnim servisima.
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php // Stanje učitavanja na dugmadima ide na svaku stranu, jer ga koriste i
      // tiket (AJAX) i uvoz ponude (obična forma koja dugo putuje). Učitava se
      // pre ostalih skripti, da im funkcija bude na raspolaganju. ?>
<script src="<?= KORENSKI_URL ?>/js/ucitavanje.js"></script>

<?php foreach ($dodatne_skripte ?? [] as $skripta): ?>
<script src="<?= KORENSKI_URL ?>/js/<?= bezbedno($skripta) ?>"></script>
<?php endforeach; ?>
</body>
</html>
