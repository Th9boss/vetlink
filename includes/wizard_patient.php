<?php
// includes/wizard_patient.php – Wizard "Nouveau patient" 3 étapes

if (!isset($clients_wizard)) {
    $r = db()->query("SELECT id, prenom, nom, gsm, adresse FROM clients ORDER BY nom, prenom");
    $clients_wizard = $r ? $r->fetchAll(PDO::FETCH_ASSOC) : [];
}
?>
<style>
/* ═══════════════════════ Dialog shell ═══════════════════════ */
dialog#wzPatient {
  border: 0; padding: 0; background: transparent;
  width: 100%; max-width: none; height: 100%; max-height: 100%;
  margin: 0; overflow: hidden;
}
dialog#wzPatient:not([open]) { display: none; }
dialog#wzPatient[open] {
  display: flex; align-items: flex-end; justify-content: center;
}
dialog#wzPatient::backdrop {
  background: rgba(9,20,40,.52);
  backdrop-filter: blur(10px) saturate(130%);
}
@media (min-width: 600px) {
  dialog#wzPatient[open] { align-items: center; }
}

/* ═══════════════════════ Box ═══════════════════════ */
.wz-box {
  width: 100%;
  max-width: 520px;
  /* occupe tout l'espace dispo sans jamais sortir de l'écran */
  height: 94svh;           /* svh = small viewport height, plus fiable sur mobile */
  max-height: 94svh;
  display: flex;
  flex-direction: column;
  background: linear-gradient(160deg, #fff 0%, #f7f9ff 100%);
  border-radius: 24px 24px 0 0;
  box-shadow: 0 -4px 60px rgba(7,19,43,.26), 0 0 0 1px rgba(255,255,255,.5) inset;
  overflow: hidden;
  /* fallback pour navigateurs sans svh */
  height: 94vh;
  max-height: 94vh;
}
@supports (height: 1svh) {
  .wz-box { height: 94svh; max-height: 94svh; }
}
@media (min-width: 600px) {
  .wz-box {
    height: auto; max-height: 88svh;
    border-radius: 24px; box-shadow: 0 28px 80px rgba(7,19,43,.28);
  }
  @supports not (height: 1svh) { .wz-box { max-height: 88vh; } }
}

/* ═══════════════════════ Header / progress ═══════════════════════ */
.wz-hdr {
  flex-shrink: 0;
  padding: 18px 20px 14px;
  border-bottom: 1px solid #e5eaf2;
  position: relative;
}
.wz-title-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}
.wz-modal-title { font-size: 1.05rem; font-weight: 700; color: #16181d; }
.wz-close-btn {
  width: 32px; height: 32px; border-radius: 50%;
  border: none; background: #e53935; cursor: pointer;
  font-size: .82rem; font-weight: 700;
  display: flex; align-items: center; justify-content: center; color: #fff;
  flex-shrink: 0; box-shadow: 0 2px 8px rgba(229,57,53,.35);
  -webkit-tap-highlight-color: transparent; touch-action: manipulation;
}
.wz-progress-row { display: flex; align-items: center; gap: 0; }
.wz-dot {
  width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
  background: #e8edf5; color: #7a86a0; font-size: .78rem; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  transition: background .2s, color .2s;
}
.wz-dot.active { background: #1a73e8; color: #fff; }
.wz-dot.done   { background: #1aaf6e; color: #fff; }
.wz-line { flex: 1; height: 2px; background: #e8edf5; transition: background .25s; }
.wz-line.done  { background: #1aaf6e; }
.wz-labels-row { display: flex; padding: 0 15px; margin-top: 6px; }
.wz-lbl { flex: 1; font-size: .7rem; font-weight: 500; color: #8892a4; text-align: center; }
.wz-lbl.active { color: #1a73e8; font-weight: 600; }
.wz-lbl.done   { color: #1aaf6e; }

/* ═══════════════════════ Step content ═══════════════════════ */
.wz-steps-wrap {
  flex: 1;
  min-height: 0;           /* critique : permet au flex-child de rétrécir → scroll actif */
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
}
.wz-step { padding: 16px 18px 16px; display: none; }
.wz-step.active { display: block; }
.wz-step-title { font-size: 1rem; font-weight: 700; color: #16181d; margin-bottom: 14px; }

/* ═══════════════════════ Footer nav ═══════════════════════ */
.wz-footer {
  flex-shrink: 0;
  padding: 14px 20px;
  /* safe-area pour iPhone avec home indicator */
  padding-bottom: max(14px, env(safe-area-inset-bottom));
  border-top: 1px solid #e5eaf2; background: #fff;
  display: flex; gap: 10px;
}
.wz-btn-sec {
  border-radius: 12px; border: 1px solid #d5dbe6;
  background: linear-gradient(180deg,#fff 0%,#f3f6fb 100%);
  color: #182033; padding: .65rem 1.1rem; font-size: .9rem;
  font-weight: 500; cursor: pointer;
  -webkit-tap-highlight-color: transparent; touch-action: manipulation;
  user-select: none;
}
.wz-btn-pri {
  border-radius: 12px; border: none;
  background: linear-gradient(180deg, #3182ff 0%, #0a5fd6 100%);
  color: #fff; padding: .65rem 1.4rem; font-size: .9rem;
  font-weight: 600; cursor: pointer; flex: 1;
  box-shadow: 0 4px 14px rgba(26,115,232,.32);
  -webkit-tap-highlight-color: transparent; touch-action: manipulation;
  user-select: none;
}
.wz-btn-pri:disabled { opacity: .45; cursor: not-allowed; }
.wz-btn-sec.d-none, .wz-btn-pri.d-none { display: none !important; }

/* ═══════════════════════ Step 1 – Client ═══════════════════════ */
.wz-search-wrap { position: relative; margin-bottom: 12px; }
.wz-search-ico {
  position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
  font-size: .95rem; pointer-events: none; color: #8892a4;
}
.wz-search-inp {
  width: 100%; padding: .78rem .78rem .78rem 2.6rem;
  border-radius: 14px; border: 1.5px solid #d5dbe6;
  font-size: 16px; background: #fff; outline: none; box-sizing: border-box;
  -webkit-appearance: none;
}
.wz-search-inp:focus { border-color: #8db8ff; box-shadow: 0 0 0 3px rgba(26,115,232,.12); }
.wz-client-list {
  max-height: 230px; overflow-y: auto;
  border-radius: 12px; border: 1px solid #e0e7f0; background: #fff;
  margin-bottom: 12px;
}
.wz-client-list:empty { display: none; }
.wz-ci {
  display: flex; flex-direction: column;
  padding: 11px 14px; border-bottom: 1px solid #f0f4fa;
  cursor: pointer; transition: background .1s;
}
.wz-ci:last-child { border-bottom: 0; }
.wz-ci:hover, .wz-ci:active { background: #eef4ff; }
.wz-ci-name { font-weight: 600; font-size: .88rem; color: #16181d; }
.wz-ci-meta { font-size: .76rem; color: #8892a4; margin-top: .1rem; }
.wz-ci-empty { padding: 12px; text-align: center; color: #8892a4; font-size: .84rem; }
.wz-selected-card {
  border-radius: 14px; border: 1.5px solid #1a73e8; background: #eef4ff;
  padding: 14px; display: flex; align-items: center; gap: 12px;
}
.wz-selected-card.d-none { display: none !important; }
.wz-sel-ico {
  width: 34px; height: 34px; border-radius: 50%; background: #1a73e8; color: #fff;
  display: flex; align-items: center; justify-content: center; font-size: .9rem; flex-shrink: 0;
}
.wz-sel-name { font-weight: 700; font-size: .92rem; color: #16181d; }
.wz-sel-meta { font-size: .76rem; color: #8892a4; margin-top: .1rem; }
.wz-change-btn {
  margin-left: auto; border: 1px solid #1a73e8; background: #fff; color: #1a73e8;
  border-radius: 10px; padding: .28rem .72rem; font-size: .78rem; cursor: pointer; flex-shrink: 0;
}

/* ═══════════════════════ Step 2 – Identity ═══════════════════════ */
/* Photo inline avec nom */
.wz-photo-nom-row { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }
.wz-photo-zone {
  width: 80px; height: 80px; border-radius: 40px; flex-shrink: 0;
  border: 2px dashed #c0cbdb; background: #f2f6fd;
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; cursor: pointer; position: relative;
  overflow: hidden; transition: border-color .15s;
}
.wz-photo-zone:hover { border-color: #1a73e8; }
.wz-photo-zone.has-photo { border-style: solid; border-color: #1a73e8; }
.wz-photo-ico { font-size: 1.9rem; line-height: 1; }
.wz-photo-lbl { font-size: .58rem; color: #8892a4; font-weight: 500; margin-top: 2px; letter-spacing: .03em; }
.wz-photo-img {
  position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover;
  display: none;
}
.wz-photo-zone.has-photo .wz-photo-img { display: block; }
.wz-photo-zone.has-photo .wz-photo-ico,
.wz-photo-zone.has-photo .wz-photo-lbl { display: none; }
.wz-nom-wrap { flex: 1; }

.wz-field { margin-bottom: 12px; }
.wz-lbl-field {
  display: block; font-size: .72rem; font-weight: 700; color: #5a6070;
  margin-bottom: 6px; text-transform: uppercase; letter-spacing: .05em;
}
.wz-inp {
  width: 100%; padding: .72rem .9rem; border-radius: 12px;
  border: 1.5px solid #d5dbe6; font-size: 16px; background: #fff;
  outline: none; box-sizing: border-box; -webkit-appearance: none;
}
.wz-inp:focus { border-color: #8db8ff; box-shadow: 0 0 0 3px rgba(26,115,232,.12); }
.wz-inp.error { border-color: #e53935; box-shadow: 0 0 0 3px rgba(229,57,53,.1); }
.wz-ta {
  width: 100%; padding: .72rem .9rem; border-radius: 12px;
  border: 1.5px solid #d5dbe6; font-size: 16px; background: #fff;
  outline: none; resize: vertical; min-height: 72px; box-sizing: border-box;
  -webkit-appearance: none;
}
.wz-ta:focus { border-color: #8db8ff; box-shadow: 0 0 0 3px rgba(26,115,232,.12); }
.wz-hint { font-size: .74rem; color: #8892a4; margin-top: 5px; }

/* Species grid – 4 espèces, 1 ligne */
.wz-species-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
}
.wz-sp-btn {
  border: 1.5px solid #d5dbe6; border-radius: 12px; background: #fff;
  padding: 10px 4px 8px; font-size: .73rem; text-align: center;
  cursor: pointer; color: #4f5a70; line-height: 1.3; transition: all .13s;
  display: flex; flex-direction: column; align-items: center; gap: 3px;
  min-height: 56px; justify-content: center;
  -webkit-tap-highlight-color: transparent; touch-action: manipulation; user-select: none;
}
.wz-sp-ico { font-size: 1.5rem; line-height: 1; }
.wz-sp-btn:hover { background: #eef4ff; border-color: #8db8ff; }
.wz-sp-btn.sel { background: #deeaff; border-color: #1a73e8; color: #1055bd; font-weight: 600; }

/* Sex group */
.wz-sex-group { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.wz-sex-btn {
  border: 1.5px solid #d5dbe6; border-radius: 12px; background: #fff;
  padding: 11px 6px; display: flex; flex-direction: column;
  align-items: center; gap: 4px; cursor: pointer; transition: all .13s;
  -webkit-tap-highlight-color: transparent; touch-action: manipulation; user-select: none;
}
.wz-sex-ico { font-size: 1.45rem; line-height: 1; }
.wz-sex-lbl { font-size: .75rem; font-weight: 500; color: #4f5a70; }
.wz-sex-btn[data-s="M"].sel  { background: #deeaff; border-color: #1a73e8; }
.wz-sex-btn[data-s="M"].sel .wz-sex-ico  { color: #1a73e8; }
.wz-sex-btn[data-s="F"].sel  { background: #fde8ef; border-color: #c2185b; }
.wz-sex-btn[data-s="F"].sel .wz-sex-ico  { color: #c2185b; }
.wz-sex-btn[data-s="I"].sel  { background: #f0f0f4; border-color: #7a86a0; }

/* ═══════════════════════ Step 3 – Medical ═══════════════════════ */
.wz-ster-btn {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 18px; border-radius: 14px; border: 1.5px solid #d5dbe6;
  background: #fff; cursor: pointer; width: 100%; text-align: left;
  transition: all .15s;
  -webkit-tap-highlight-color: transparent; touch-action: manipulation; user-select: none;
}
.wz-ster-ico { font-size: 1.3rem; }
.wz-ster-lbl { font-size: .9rem; font-weight: 500; color: #4f5a70; }
.wz-ster-btn.on { background: #e6f9ef; border-color: #1aaf6e; }
.wz-ster-btn.on .wz-ster-lbl { color: #0a7a4e; font-weight: 600; }

/* ── Date naissance row ── */
.wz-dob-row { display: flex; gap: 7px; align-items: stretch; }
.wz-dob-inp { flex: 1; min-width: 0; }
.wz-slash-btn, .wz-mois-btn {
  flex-shrink: 0;
  border-radius: 11px; border: 1.5px solid #d5dbe6;
  background: #f3f6fb; color: #4f5a70;
  font-weight: 700; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .12s, border-color .12s;
  -webkit-tap-highlight-color: transparent; touch-action: manipulation; user-select: none;
}
.wz-slash-btn { width: 42px; font-size: 1.15rem; }
.wz-mois-btn  { padding: 0 11px; font-size: .8rem; letter-spacing: .02em; }
.wz-slash-btn:active, .wz-mois-btn:active { background: #deeaff; border-color: #8db8ff; color: #1a73e8; }

/* ═══════════════════════ Error banner ═══════════════════════ */
.wz-error {
  background: #fde8e8; border: 1px solid #f5c6c6; color: #b02020;
  border-radius: 10px; padding: 9px 14px; font-size: .84rem; margin-bottom: 14px;
  display: none;
}
.wz-error.show { display: block; }
</style>

<dialog id="wzPatient">
  <div class="wz-box">

    <!-- ── Header ── -->
    <div class="wz-hdr">
      <div class="wz-title-row">
        <span class="wz-modal-title">Nouveau patient</span>
        <button type="button" class="wz-close-btn" id="wzClose">✕</button>
      </div>
      <div class="wz-progress-row">
        <div class="wz-dot active" id="wzd1">1</div>
        <div class="wz-line" id="wzl1"></div>
        <div class="wz-dot" id="wzd2">2</div>
        <div class="wz-line" id="wzl2"></div>
        <div class="wz-dot" id="wzd3">3</div>
      </div>
      <div class="wz-labels-row">
        <span class="wz-lbl active" id="wzlbl1">Client</span>
        <span class="wz-lbl" id="wzlbl2">Identité</span>
        <span class="wz-lbl" id="wzlbl3">Médical</span>
      </div>
    </div>

    <!-- ── Steps wrap (scrollable) ── -->
    <div class="wz-steps-wrap">
      <form id="wzForm" method="post" action="index.php?page=patients">
        <?= csrf_input() ?>
        <input type="hidden" name="action"         value="create">
        <input type="hidden" name="id"             id="wzPatientId" value="">
        <input type="hidden" name="client_id"      id="wzClientId">
        <input type="hidden" name="sexe"           id="wzSexe"     value="INCONNU">
        <input type="hidden" name="espece"         id="wzEspece">
        <input type="hidden" name="date_naissance" id="wzDob">
        <input type="hidden" name="photo_b64"      id="wzPhotoB64">
        <!-- sterilise: only present when active (disabled=not submitted) -->
        <input type="hidden" name="sterilise" id="wzSterVal" value="1" disabled>

        <!-- ══ STEP 1 : CLIENT ══ -->
        <div class="wz-step active" id="wzS1">
          <div class="wz-step-title">Sélectionner le client</div>
          <div class="wz-error" id="wzErrS1">Veuillez sélectionner un client dans la liste.</div>

          <div class="wz-search-wrap">
            <span class="wz-search-ico">🔍</span>
            <input type="text" id="wzClientSearch" class="wz-search-inp"
                   placeholder="Nom, prénom…" autocomplete="off" autocorrect="off" autocapitalize="words">
          </div>
          <div id="wzClientList" class="wz-client-list"></div>

          <div class="wz-selected-card d-none" id="wzSelCard">
            <div class="wz-sel-ico">✓</div>
            <div>
              <div class="wz-sel-name" id="wzSelName"></div>
              <div class="wz-sel-meta" id="wzSelMeta"></div>
            </div>
            <button type="button" class="wz-change-btn" id="wzChangeCli">Changer</button>
          </div>
        </div>

        <!-- ══ STEP 2 : IDENTITÉ ══ -->
        <div class="wz-step" id="wzS2">
          <div class="wz-step-title">Identité de l'animal</div>
          <div class="wz-error" id="wzErrS2">Le nom de l'animal est obligatoire.</div>

          <input type="file" id="wzPhotoFile" accept="image/*" style="display:none">

          <!-- Photo + Nom sur la même ligne -->
          <div class="wz-photo-nom-row">
            <div class="wz-photo-zone" id="wzPhotoZone" title="Ajouter une photo">
              <span class="wz-photo-ico">🐾</span>
              <span class="wz-photo-lbl">PHOTO</span>
              <img class="wz-photo-img" id="wzPhotoImg" alt="">
            </div>
            <div class="wz-nom-wrap">
              <label class="wz-lbl-field" for="wzNom">Nom *</label>
              <input type="text" id="wzNom" name="nom" class="wz-inp"
                     placeholder="Max, Luna…" autocomplete="off" autocapitalize="words">
            </div>
          </div>

          <!-- Espèce : 4 boutons -->
          <div class="wz-field">
            <label class="wz-lbl-field">Espèce</label>
            <div class="wz-species-grid" id="wzSpeciesGrid">
              <button type="button" class="wz-sp-btn" data-sp="Chien">
                <span class="wz-sp-ico">🐕</span>Chien
              </button>
              <button type="button" class="wz-sp-btn" data-sp="Chat">
                <span class="wz-sp-ico">🐈</span>Chat
              </button>
              <button type="button" class="wz-sp-btn" data-sp="Cheval">
                <span class="wz-sp-ico">🐴</span>Cheval
              </button>
              <button type="button" class="wz-sp-btn" data-sp="">
                <span class="wz-sp-ico">✏️</span>Autre
              </button>
            </div>
            <input type="text" id="wzEspeceOther" class="wz-inp mt-2"
                   placeholder="Saisir l'espèce…" style="display:none" autocomplete="off">
          </div>

          <!-- Sexe -->
          <div class="wz-field">
            <label class="wz-lbl-field">Sexe</label>
            <div class="wz-sex-group">
              <button type="button" class="wz-sex-btn" data-s="M">
                <span class="wz-sex-ico" style="color:#1a73e8">♂</span>
                <span class="wz-sex-lbl">Mâle</span>
              </button>
              <button type="button" class="wz-sex-btn" data-s="F">
                <span class="wz-sex-ico" style="color:#c2185b">♀</span>
                <span class="wz-sex-lbl">Femelle</span>
              </button>
              <button type="button" class="wz-sex-btn sel" data-s="I">
                <span class="wz-sex-ico" style="color:#7a86a0">·</span>
                <span class="wz-sex-lbl">Inconnu</span>
              </button>
            </div>
          </div>

          <!-- Naissance pleine largeur -->
          <div class="wz-field">
            <label class="wz-lbl-field" for="wzDobFree">Naissance</label>
            <div class="wz-dob-row">
              <input type="text" id="wzDobFree" class="wz-inp wz-dob-inp"
                     placeholder="jj/mm/aaaa" inputmode="numeric">
              <button type="button" class="wz-slash-btn" id="wzDobSlash" tabindex="-1">/</button>
              <button type="button" class="wz-mois-btn" id="wzDobMois" tabindex="-1">mois</button>
            </div>
            <div class="wz-hint" id="wzDobHint">ex : 2004 · 6m · 01/05/2003</div>
          </div>

          <!-- Race -->
          <div class="wz-field" style="margin-bottom:0">
            <label class="wz-lbl-field" for="wzRace">Race</label>
            <input type="text" id="wzRace" name="race" class="wz-inp"
                   placeholder="Labrador, Persan…" autocomplete="off">
          </div>
        </div>

        <!-- ══ STEP 3 : MÉDICAL ══ -->
        <div class="wz-step" id="wzS3">
          <div class="wz-step-title">Informations médicales</div>

          <!-- Identification -->
          <div class="wz-field">
            <label class="wz-lbl-field" for="wzIdent">N° Identification (puce / tatouage)</label>
            <input type="text" id="wzIdent" name="identification" class="wz-inp"
                   placeholder="Ex : 250268731234567" autocomplete="off" inputmode="numeric">
          </div>

          <!-- Stérilisé -->
          <div class="wz-field">
            <label class="wz-lbl-field">Stérilisé·e</label>
            <button type="button" class="wz-ster-btn" id="wzSterBtn">
              <span class="wz-ster-ico">✂</span>
              <span class="wz-ster-lbl" id="wzSterLbl">Non stérilisé·e — cliquer pour activer</span>
            </button>
          </div>

          <!-- Allergies -->
          <div class="wz-field">
            <label class="wz-lbl-field" for="wzAllergies">Allergies</label>
            <textarea id="wzAllergies" name="allergies" class="wz-ta"
                      rows="2" placeholder="Pénicilline, pollen…"></textarea>
          </div>

          <!-- Notes -->
          <div class="wz-field">
            <label class="wz-lbl-field" for="wzNotes">Notes</label>
            <textarea id="wzNotes" name="notes" class="wz-ta"
                      rows="3" placeholder="Informations complémentaires…"></textarea>
          </div>
        </div>

      </form><!-- #wzForm -->
    </div><!-- .wz-steps-wrap -->

    <!-- ── Footer ── -->
    <div class="wz-footer">
      <button type="button" class="wz-btn-sec d-none" id="wzPrev">← Précédent</button>
      <button type="button" class="wz-btn-pri" id="wzNext">Suivant →</button>
      <button type="submit" form="wzForm" class="wz-btn-pri d-none" id="wzSubmit">
        ✓ Enregistrer
      </button>
    </div>

  </div><!-- .wz-box -->
</dialog>

<script>
(function(){
  'use strict';
  const dlg = document.getElementById('wzPatient');
  if (!dlg) return;

  /* ── Clients data from PHP ── */
  const WZ_CLIENTS = <?= json_encode(
    array_map(function($c){
      return [
        'id'    => (int)$c['id'],
        'label' => trim(($c['nom']??'').' '.($c['prenom']??'')),
        'gsm'   => $c['gsm'] ?? '',
        'addr'  => $c['adresse'] ?? '',
      ];
    }, $clients_wizard),
    JSON_UNESCAPED_UNICODE
  ) ?>;

  /* ── State ── */
  let step = 1;
  let selClient = null;

  /* ── DOM refs ── */
  const $ = id => document.getElementById(id);
  const steps   = [$('wzS1'), $('wzS2'), $('wzS3')];
  const dots    = [$('wzd1'), $('wzd2'), $('wzd3')];
  const lines   = [$('wzl1'), $('wzl2')];
  const lbls    = [$('wzlbl1'), $('wzlbl2'), $('wzlbl3')];
  const btnPrev = $('wzPrev');
  const btnNext = $('wzNext');
  const btnSub  = $('wzSubmit');

  /* ════════════════════════════════════════
     Step navigation
  ════════════════════════════════════════ */
  function goTo(n){
    steps.forEach((s,i) => s.classList.toggle('active', i===n-1));
    dots.forEach((d,i) => {
      d.classList.remove('active','done');
      lbls[i].classList.remove('active','done');
      if (i+1 < n){ d.classList.add('done'); d.textContent='✓'; lbls[i].classList.add('done'); }
      else if (i+1===n){ d.classList.add('active'); d.textContent=String(n); lbls[i].classList.add('active'); }
      else { d.textContent=String(i+1); }
    });
    lines.forEach((l,i) => l.classList.toggle('done', i+2<=n));
    btnPrev.classList.toggle('d-none', n===1);
    btnNext.classList.toggle('d-none', n===3);
    btnSub.classList.toggle('d-none',  n!==3);
    step = n;
    // scroll to top of steps
    document.querySelector('.wz-steps-wrap').scrollTop = 0;
  }

  function validate(n){
    hideErr(n);
    if (n===1){
      if (!selClient){ showErr(1); $('wzClientSearch').focus(); return false; }
    }
    if (n===2){
      const nom = $('wzNom').value.trim();
      if (!nom){ showErr(2); $('wzNom').classList.add('error'); $('wzNom').focus(); return false; }
    }
    return true;
  }
  function showErr(n){ $('wzErrS'+n)?.classList.add('show'); }
  function hideErr(n){ $('wzErrS'+n)?.classList.remove('show'); }

  btnNext.addEventListener('click', () => { if (validate(step)) goTo(step+1); });
  btnPrev.addEventListener('click', () => goTo(step-1));

  /* ── Close ── */
  $('wzClose').addEventListener('click', () => dlg.close());
  dlg.addEventListener('click', e => { if (e.target===dlg) dlg.close(); });

  /* ════════════════════════════════════════
     STEP 1 – Client search
  ════════════════════════════════════════ */
  const search   = $('wzClientSearch');
  const cList    = $('wzClientList');
  const selCard  = $('wzSelCard');
  const clientId = $('wzClientId');

  function esc(s){ return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function escA(s){ return (s||'').replace(/"/g,'&quot;'); }

  function renderList(items){
    if (!items.length){
      cList.innerHTML = '<div class="wz-ci-empty">Aucun résultat</div>';
      return;
    }
    cList.innerHTML = items.slice(0,20).map(c => {
      const meta = [c.gsm, c.addr].filter(Boolean).join(' · ');
      return `<div class="wz-ci" data-id="${c.id}" data-name="${escA(c.label)}" data-meta="${escA(meta)}">
        <div class="wz-ci-name">${esc(c.label)}</div>
        ${meta?`<div class="wz-ci-meta">${esc(meta)}</div>`:''}
      </div>`;
    }).join('');
    cList.querySelectorAll('.wz-ci').forEach(el => {
      el.addEventListener('click', () => pickClient({
        id: el.dataset.id, label: el.dataset.name, meta: el.dataset.meta
      }));
    });
  }

  function pickClient(c){
    selClient = c;
    clientId.value = c.id;
    $('wzSelName').textContent = c.label;
    $('wzSelMeta').textContent = c.meta || '';
    cList.innerHTML = '';
    search.value = '';
    selCard.classList.remove('d-none');
    hideErr(1);
  }

  $('wzChangeCli').addEventListener('click', () => {
    selClient = null; clientId.value = '';
    selCard.classList.add('d-none');
    search.value = ''; search.focus();
    renderList(WZ_CLIENTS.slice(0,20));
  });

  search.addEventListener('focus', () => {
    if (!search.value.trim()) renderList(WZ_CLIENTS.slice(0,20));
  });
  search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    if (!q){ renderList(WZ_CLIENTS.slice(0,20)); return; }
    const toks = q.split(/\s+/);
    renderList(WZ_CLIENTS.filter(c => {
      const lab = c.label.toLowerCase();
      return toks.every(t => lab.includes(t));
    }));
  });

  /* ════════════════════════════════════════
     STEP 2 – Species
  ════════════════════════════════════════ */
  document.getElementById('wzSpeciesGrid').addEventListener('click', e => {
    const btn = e.target.closest('.wz-sp-btn');
    if (!btn) return;
    document.querySelectorAll('.wz-sp-btn').forEach(b => b.classList.remove('sel'));
    btn.classList.add('sel');
    const sp = btn.dataset.sp;
    const other = $('wzEspeceOther');
    if (sp === ''){
      other.style.display = '';
      other.focus();
      $('wzEspece').value = '';
    } else {
      other.style.display = 'none';
      $('wzEspece').value = sp;
    }
  });
  $('wzEspeceOther').addEventListener('input', e => { $('wzEspece').value = e.target.value.trim(); });

  /* ── Sex ── */
  const sexMap = { M:'M', F:'F', I:'INCONNU' };
  document.querySelectorAll('.wz-sex-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.wz-sex-btn').forEach(b => b.classList.remove('sel'));
      btn.classList.add('sel');
      $('wzSexe').value = sexMap[btn.dataset.s] || 'INCONNU';
    });
  });

  /* ── Date de naissance ── */
  function toIso(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
  function toFr(iso){ if(!iso) return ''; const[y,m,d]=iso.split('-'); return `${d}/${m}/${y}`; }
  function parseDob(v){
    if (!v) return '';
    v = v.trim();
    // ISO direct
    if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
    // Année seule : 1990–2099 → 01/01/YYYY
    if (/^(19|20)\d{2}$/.test(v)){
      return v + '-01-01';
    }
    // Date FR complète : jj/mm/aaaa ou jj/mm/aa
    const fr = v.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/);
    if (fr){
      let yr = +fr[3];
      if (yr < 100) yr += yr >= 50 ? 1900 : 2000;
      const dt = new Date(yr, +fr[2]-1, +fr[1]);
      if (!isNaN(dt) && dt.getFullYear()===yr) return toIso(dt);
    }
    // Âge en mois : 6 / 6m / 18 mois
    const mo = v.toLowerCase().match(/^(\d+)\s*(m|mois)?$/);
    if (mo){
      const months = +mo[1];
      if (months >= 0 && months < 600){
        const dt = new Date();
        dt.setMonth(dt.getMonth()-months);
        return toIso(dt);
      }
    }
    return '';
  }
  const dobFree = $('wzDobFree');
  const dobHid  = $('wzDob');
  const dobHint = $('wzDobHint');
  /* Bouton "/" */
  document.getElementById('wzDobSlash').addEventListener('click', () => {
    const s = dobFree.selectionStart ?? dobFree.value.length;
    const e = dobFree.selectionEnd   ?? s;
    dobFree.value = dobFree.value.slice(0, s) + '/' + dobFree.value.slice(e);
    dobFree.setSelectionRange(s+1, s+1);
    dobFree.focus();
    dobFree.dispatchEvent(new Event('input'));
  });

  /* Bouton "mois" : remplace le contenu par "<chiffres>m" */
  document.getElementById('wzDobMois').addEventListener('click', () => {
    const digits = dobFree.value.replace(/\D/g, '');
    dobFree.value = digits ? digits + 'm' : '';
    dobFree.focus();
    dobFree.dispatchEvent(new Event('input'));
  });

  dobFree.addEventListener('input', () => {
    const iso = parseDob(dobFree.value);
    dobHid.value = iso;
    if (iso){ dobHint.textContent='→ '+toFr(iso); dobHint.style.color='#1aaf6e'; }
    else if (dobFree.value.trim()){ dobHint.textContent='Format non reconnu'; dobHint.style.color='#e53935'; }
    else { dobHint.textContent='Exemples : 01/05/2023 · 6 · 6m · 18 mois'; dobHint.style.color='#8892a4'; }
  });

  /* ── Nom (clear error on input) ── */
  $('wzNom').addEventListener('input', () => {
    $('wzNom').classList.remove('error');
    hideErr(2);
  });

  /* ── Photo ── */
  const photoZone = $('wzPhotoZone');
  const photoFile = $('wzPhotoFile');
  const photoImg  = $('wzPhotoImg');
  const photoB64  = $('wzPhotoB64');

  photoZone.addEventListener('click', () => photoFile.click());
  photoFile.addEventListener('change', async () => {
    const file = photoFile.files && photoFile.files[0];
    if (!file) return;
    try {
      const b64 = await compressImg(file);
      photoB64.value = b64;
      photoImg.src = b64;
      photoZone.classList.add('has-photo');
    } catch(e){ console.error(e); }
  });
  function compressImg(file, maxW=900, q=0.82){
    return new Promise((ok,fail) => {
      const fr = new FileReader();
      fr.onload = e => {
        const img = new Image();
        img.onload = () => {
          const sc = Math.min(1, maxW/img.width);
          const cv = document.createElement('canvas');
          cv.width=Math.round(img.width*sc); cv.height=Math.round(img.height*sc);
          cv.getContext('2d').drawImage(img,0,0,cv.width,cv.height);
          ok(cv.toDataURL('image/jpeg',q));
        };
        img.onerror = fail;
        img.src = e.target.result;
      };
      fr.onerror = fail;
      fr.readAsDataURL(file);
    });
  }

  /* ════════════════════════════════════════
     STEP 3 – Sterilise
  ════════════════════════════════════════ */
  const sterBtn = $('wzSterBtn');
  const sterVal = $('wzSterVal');
  const sterLbl = $('wzSterLbl');
  sterBtn.addEventListener('click', () => {
    const on = sterBtn.classList.toggle('on');
    sterVal.disabled = !on;
    sterLbl.textContent = on ? 'Stérilisé·e ✓' : 'Non stérilisé·e — cliquer pour activer';
  });

  /* ── Form submit validation ── */
  $('wzForm').addEventListener('submit', e => {
    const nom = $('wzNom').value.trim();
    if (!clientId.value){ e.preventDefault(); goTo(1); showErr(1); return; }
    if (!nom){ e.preventDefault(); goTo(2); showErr(2); $('wzNom').classList.add('error'); $('wzNom').focus(); return; }
    if (dobFree.value.trim() && !dobHid.value){
      e.preventDefault(); goTo(2);
      dobFree.classList.add('error');
      dobHint.textContent='Format de date non reconnu'; dobHint.style.color='#e53935';
      dobFree.focus();
    }
  });

  /* ════════════════════════════════════════
     Public: open wizard
  ════════════════════════════════════════ */
  function reset(){
    $('wzForm').querySelector('[name=action]').value = 'create';
    $('wzPatientId').value = '';
    // step 1
    selClient = null; clientId.value = '';
    selCard.classList.add('d-none');
    search.value = ''; cList.innerHTML = '';
    // step 2
    $('wzNom').value = ''; $('wzNom').classList.remove('error');
    $('wzRace').value = '';
    $('wzEspece').value = ''; $('wzEspeceOther').value = ''; $('wzEspeceOther').style.display='none';
    document.querySelectorAll('.wz-sp-btn').forEach(b => b.classList.remove('sel'));
    $('wzSexe').value='INCONNU';
    document.querySelectorAll('.wz-sex-btn').forEach(b => b.classList.remove('sel'));
    document.querySelector('.wz-sex-btn[data-s="I"]').classList.add('sel');
    dobFree.value=''; dobHid.value=''; dobHint.textContent='Exemples : 01/05/2023 · 6 · 6m · 18 mois'; dobHint.style.color='#8892a4';
    photoZone.classList.remove('has-photo'); photoImg.src=''; photoB64.value=''; photoFile.value='';
    // step 3
    $('wzIdent').value=''; $('wzAllergies').value=''; $('wzNotes').value='';
    sterBtn.classList.remove('on'); sterVal.disabled=true;
    sterLbl.textContent='Non stérilisé·e — cliquer pour activer';
    [1,2,3].forEach(hideErr);
    goTo(1);
  }

  window.openWizardPatient = function(clientId_, clientLabel_){
    reset();
    if (clientId_ && clientLabel_){
      pickClient({ id: clientId_, label: clientLabel_, meta: '' });
    }
    dlg.showModal();
    setTimeout(() => { if (!selClient) search.focus(); }, 80);
  };

  window.openWizardPatientEdit = function(p){
    reset();
    // mode update
    $('wzForm').querySelector('[name=action]').value = 'update';
    $('wzPatientId').value = p.id || '';

    // pré-sélectionner le client
    const cFound = WZ_CLIENTS.find(x => x.id === parseInt(p.client_id, 10));
    if (cFound) {
      pickClient({ id: cFound.id, label: cFound.label, meta: [cFound.gsm, cFound.addr].filter(Boolean).join(' · ') });
    } else if (p.client_id) {
      pickClient({ id: p.client_id, label: p.client_label || ('Client #' + p.client_id), meta: '' });
    }

    // step 2 – identité
    $('wzNom').value = p.nom || '';

    // espèce
    const esp = p.espece || '';
    document.querySelectorAll('.wz-sp-btn').forEach(b => b.classList.remove('sel'));
    const spBtn = document.querySelector(`.wz-sp-btn[data-sp="${esp.replace(/"/g,'&quot;')}"]`);
    if (spBtn) {
      spBtn.classList.add('sel');
      $('wzEspece').value = esp;
      $('wzEspeceOther').style.display = 'none';
    } else if (esp) {
      const otherBtn = document.querySelector('.wz-sp-btn[data-sp=""]');
      if (otherBtn) otherBtn.classList.add('sel');
      $('wzEspece').value = esp;
      $('wzEspeceOther').value = esp;
      $('wzEspeceOther').style.display = '';
    }

    // race
    $('wzRace').value = p.race || '';

    // sexe
    const sexMap2 = { 'M':'M', 'F':'F', 'INCONNU':'I' };
    const sKey = sexMap2[p.sexe] || 'I';
    document.querySelectorAll('.wz-sex-btn').forEach(b => b.classList.remove('sel'));
    const sBtn = document.querySelector(`.wz-sex-btn[data-s="${sKey}"]`);
    if (sBtn) sBtn.classList.add('sel');
    $('wzSexe').value = p.sexe || 'INCONNU';

    // date de naissance
    const iso = (p.date_naissance || '').trim();
    dobFree.value = iso ? toFr(iso) : '';
    dobHid.value  = iso;
    dobHint.textContent = iso ? '→ Interprété comme date : ' + toFr(iso) : 'Exemples : 01/05/2023 · 6 · 6m · 18 mois';
    dobHint.style.color = '#8892a4';

    // photo
    if (p.photo) {
      photoImg.src = p.photo;
      photoZone.classList.add('has-photo');
      photoB64.value = '';
    }

    // step 3 – médical
    $('wzIdent').value = p.identification || '';
    if (p.sterilise == 1) {
      sterBtn.classList.add('on');
      sterVal.disabled = false;
      sterLbl.textContent = 'Stérilisé·e ✓ — cliquer pour désactiver';
    }
    $('wzAllergies').value = p.allergies || '';
    $('wzNotes').value     = p.notes || '';

    dlg.showModal();
    goTo(2);
  };

  goTo(1);

  /* Auto-open when page loaded with ?new_for=id&new_name=name */
  (function(){
    const params = new URLSearchParams(window.location.search);
    const nf = params.get('new_for');
    const nn = params.get('new_name');
    if (nf && nn) {
      // Clean URL without reload
      const u = new URL(window.location.href);
      u.searchParams.delete('new_for');
      u.searchParams.delete('new_name');
      history.replaceState({}, '', u.toString());
      setTimeout(() => window.openWizardPatient(nf, decodeURIComponent(nn)), 120);
    }
  })();
})();
</script>
