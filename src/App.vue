<template>
  <div id="app" class="app-container">
    <header class="main-header">
      <div class="header-left">
        <div class="burger-menu" :class="{ 'is-active': showSettings }" @click="toggleMenu">
          <div class="burger-lines">
            <span></span>
            <span></span>
            <span></span>
          </div>
        </div>
      </div>
      <div class="header-right">
        <button v-if="view !== 'home'" class="back-circle" @click="goBack">
          <span class="arrow">&lt;</span>
        </button>
      </div>
    </header>

    <transition name="fade">
      <div v-if="showSettings" class="overlay" @click="toggleMenu"></div>
    </transition>

    <transition name="slide">
      <div v-if="showSettings" class="settings-sidebar glass">
        <div class="input-group">
          <label>Schuljahr:</label>
          <div class="schuljahr-manager">
            <div class="year-list">
              <div
                  v-for="yearObj in schuljahre"
                  :key="yearObj.id"
                  class="year-item"
                  :class="{ active: yearObj.id === currentSchuljahrId }"
                  @click="selectYear(yearObj)"
              >
                {{ yearObj.schuljahr }}
              </div>
            </div>
            <button class="add-year-btn" @click="addYear">+</button>
          </div>
        </div>

        <div class="menu-item settings-section">
          <h3>Einstellungen</h3>
          <div class="input-group">
            <label>Dein Anzeigename</label>
            <input
                v-model="nutzerName"
                @change="saveNutzerName"
                type="text"
                class="glass-input"
            >
          </div>
        </div>

        <div class="input-group">
          <label>Adresse:</label>
          <textarea v-model="schule.adresse.name" placeholder="Name der Schule"></textarea>
          <textarea v-model="schule.adresse.strasse" placeholder="Straße"></textarea>
          <textarea v-model="schule.adresse.stadt" placeholder="Postleitzahl & Ort"></textarea>
          <button class="save-btn" @click="saveAddressManual">Adresse speichern</button>
        </div>
      </div>
    </transition>

    <main class="content-full">
      <div v-if="view === 'home'">
        <div class="hero-section">
          <h1 class="main-title">Hallo {{ nutzerName }}</h1>
          <h2 class="sub-title">Was willst du bearbeiten?</h2>
        </div>

        <div class="grid-layout top-grid">
          <button
              v-for="key in ['aktivitaet', 'erstkraft', 'raum', 'schulfach', 'zweitkraft']"
              :key="key"
              class="glass-btn btn-accent"
              @click="navigate(key)"
          >
            <img :src="categoryMap[key].icon" class="custom-icon-svg" alt="Icon">
            <span class="btn-text">{{ categoryMap[key].plural }}</span>
          </button>
        </div>

        <div class="separator-full"></div>

        <div class="grid-layout bottom-grid">
          <button
              v-for="key in ['diensteinsatzplan', 'gesamtplan', 'lehrerstundenplan', 'raumbelegungsplan', 'schuelerstundenplan']"
              :key="key"
              class="glass-btn btn-accent bottom"
              @click="navigate(key)"
          >
            <img :src="categoryMap[key].icon" class="custom-icon-svg" alt="Icon">
            <span class="btn-text">{{ categoryMap[key].plural }}</span>
          </button>

          <button class="glass-btn btn-rainbow" @click="$refs.fileInput.click()">
            <span>Dokument(e) importieren</span>
          </button>

          <input
              type="file"
              ref="fileInput"
              style="display: none"
              multiple
              accept=".doc,.docx,.pdf,.xlsx"
              @change="handleFileUpload"
          />
        </div>
      </div>

      <div v-if="view === 'list'" class="list-container">
        <div class="hero-section small">
          <div class="title-with-icon">
            <img
                v-if="categoryMap[activeCategory]"
                :src="getIcon(activeCategory)"
                class="title-icon-svg"
                alt="Icon"/>
            <h1 class="main-title">
              {{ categoryMap[activeCategory]?.plural || activeCategory }}
            </h1>
          </div>
        </div>

        <div class="grid-layout category-grid">
          <button
              v-for="item in currentItems"
              :key="item.id || item.name"
              class="glass-btn btn-accent item-button"
              @click="editItem(item)"
              :style="{ background: activeCategory === 'schulfach' ? item.farbe : null }"
          >
            <span class="button-content-wrapper">
              <span class="item-name">{{ item.name }}</span>
              <small v-if="activeCategory === 'aktivitaet'" class="item-type">
                {{ item.typ }}
              </small>
            </span>

            <span
                v-if="activeCategory !== 'raumbelegungsplan' && activeCategory !== 'lehrerstundenplan' && activeCategory !== 'diensteinsatzplan'"
                class="delete-overlay-btn"
                @click.stop="deleteElement(item)">×</span>
          </button>

          <button
              v-if="activeCategory !== 'raumbelegungsplan' && activeCategory !== 'lehrerstundenplan' && activeCategory !== 'diensteinsatzplan'"
              class="glass-btn btn-add" @click="addNewElement">
            <span>+ Neues Element hinzufügen</span>
          </button>
        </div>
      </div>

      <div v-if="view === 'editor' && activeCategory === 'aktivitaet'" class="editor-container glass">
        <div class="hero-section small">
          <h1 class="main-title">{{ currentActivity.id ? 'Aktivität bearbeiten' : 'Neue Aktivität' }}</h1>
        </div>

        <div class="editor-grid-aktivitaet">
          <div class="input-group">
            <label>Name der Aktivität:</label>
            <input v-model="currentActivity.name" type="text" placeholder="z.B. Elternsprechstunde">
          </div>

          <div class="input-group">
            <label>Typ:</label>
            <div class="custom-select-wrapper">
              <div class="custom-select-trigger" @click.stop="toggleDropdown('type')">
                <span>{{ currentActivity.typ || 'Typ wählen...' }}</span>
                <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('type') }">▼</span>
              </div>

              <transition name="fade">
                <div v-if="isDropdownOpen('type')" class="custom-options glass">
                  <div
                      v-for="t in ['AG','MSD/MSH','SVE','Mobile Reserve','Elternsprechstunde','Referendarsbetreuung','Förderunterricht','Systembetreuung','IB']"
                      :key="t"
                      class="custom-option"
                      @click="selectType(t)"
                  >
                    {{ t }}
                  </div>
                </div>
              </transition>
            </div>
          </div>

          <div class="input-group">
            <label>Einsatzort:</label>
            <div class="custom-select-wrapper">
              <div class="custom-select-trigger" @click.stop="toggleDropdown('aktivitaet-einsatzort')">
                <span>{{ currentActivity.einsatzort || 'Einsatzort wählen...' }}</span>
                <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('aktivitaet-einsatzort') }">▼</span>
              </div>

              <transition name="fade">
                <div v-if="isDropdownOpen('aktivitaet-einsatzort')" class="custom-options glass">
                  <div v-if="einsatzorte.length === 0" class="custom-option" style="opacity: 0.6; cursor: default;">
                    Keine Einsatzorte hinterlegt
                  </div>
                  <div
                      v-for="ort in einsatzorte"
                      :key="ort"
                      class="custom-option"
                      :class="{ selected: currentActivity.einsatzort === ort }"
                      @click="selectEinsatzort(ort)"
                  >
                    {{ ort }}
                    <span v-if="currentActivity.einsatzort === ort">✓</span>
                  </div>
                </div>
              </transition>
            </div>
          </div>

          <div class="input-group full-width">
            <label>Termine (Besetzung, Ort & Zeit):</label>

            <div v-for="(termin, index) in currentActivity.termine" :key="index" class="termin-card glass">
              <div class="termin-main-row">
                <div class="custom-select-wrapper tag-select">
                  <div class="custom-select-trigger" @click.stop="toggleDropdown('tag-' + index)">
                    <span>{{ termin.tag || 'Tag wählen...' }}</span>
                    <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('tag-' + index) }">▼</span>
                  </div>
                  <transition name="fade">
                    <div v-if="isDropdownOpen('tag-' + index)" class="custom-options glass">
                      <div v-for="d in days" :key="d" class="custom-option" @click="selectTagForTermin(index, d)">
                        {{ d }}
                      </div>
                    </div>
                  </transition>
                </div>

                <div class="time-col-wrapper">
                  <input v-model="termin.uhrzeit" type="time" class="glass-input time-input">
                  <input v-model="termin.endzeit" type="time" class="glass-input time-input">
                </div>

                <button class="remove-btn" @click="currentActivity.termine.splice(index, 1)">×</button>
              </div>

              <div class="termin-row mt-10">
                <div class="custom-select-wrapper" style="flex: 1;">
                  <div class="custom-select-trigger" @click.stop="toggleDropdown('verant-' + index)">
                    <span>{{ getVerantwortlicheNamen(termin) }}</span>
                    <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('verant-' + index) }">▼</span>
                  </div>

                  <transition name="fade">
                    <div v-if="isDropdownOpen('verant-' + index)" class="custom-options glass">
                      <div class="dropdown-header">Erstkräfte</div>
                      <div v-for="e in erstkraefte" :key="'e-' + e.id"
                           class="custom-option"
                           :class="{ selected: termin.verantwortliche.includes('e-' + e.id) }"
                           @click.stop="toggleSelection(termin.verantwortliche, 'e-' + e.id)">
                        {{ e.name }} <span v-if="termin.verantwortliche.includes('e-' + e.id)">✓</span>
                      </div>

                      <div class="custom-option add-option" @click.stop="openQuickAdd('erstkraft', index)">
                        + Erstkraft hinzufügen
                      </div>

                      <div class="separator-inner"></div>

                      <div class="dropdown-header">Zweitkräfte</div>
                      <div v-for="z in zweitkraefte" :key="'z-' + z.id"
                           class="custom-option"
                           :class="{ selected: termin.verantwortliche.includes('z-' + z.id) }"
                           @click.stop="toggleSelection(termin.verantwortliche, 'z-' + z.id)">
                        {{ z.name }} <span v-if="termin.verantwortliche.includes('z-' + z.id)">✓</span>
                      </div>

                      <div class="custom-option add-option" @click.stop="openQuickAdd('zweitkraft', index)">
                        + Zweitkraft hinzufügen
                      </div>
                    </div>
                  </transition>
                </div>

                <div class="time-col-wrapper">
                  <div class="custom-select-wrapper" style="flex: 1;">
                    <div
                        class="custom-select-trigger"
                        :class="{ 'rotate': isDropdownOpen('raum-' + index),
                          'border-error': termin.raum_id && !isRaumVerfuegbar(termin.raum_id, termin.tag, termin.uhrzeit, termin.endzeit)
                      }"
                        @click.stop="toggleDropdown('raum-' + index)"
                    >
                      <span>{{ getRaumNamen(termin) }}</span>
                      <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('raum-' + index) }">▼</span>
                    </div>

                    <div
                        v-if="termin.raum_id && !isRaumVerfuegbar(termin.raum_id, termin.tag, termin.uhrzeit, termin.endzeit)"
                        class="error-message-inline"
                    >
                    </div>
                    <transition name="fade">
                      <div v-if="isDropdownOpen('raum-' + index)" class="custom-options glass">
                        <div v-for="r in raeume" :key="r.id"
                             class="custom-option"
                             :class="{ selected: termin.raeume.includes(r.id) }"
                             @click.stop="toggleSelection(termin.raeume, r.id)">
                          {{ r.name }}
                          <span v-if="termin.raeume.some(id => id == r.id)">✓</span>
                        </div>
                        <div class="custom-option add-option" @click.stop="openQuickAdd('raum', index)">+ Raum
                          hinzufügen
                        </div>
                      </div>
                    </transition>
                  </div>
                </div>

              </div>
            </div>

            <button class="glass-btn btn-add-inline" @click="addTermin">+ Termin hinzufügen</button>
            <div class="form-footer">
              <button
                  class="glass-btn btn-save-main"
                  :disabled="!isFormValid"
                  :class="{ 'btn-disabled': !isFormValid }"
                  @click="saveActivity"
              >
                <span class="save-icon">💾</span> Aktivität speichern
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="view === 'editor' && activeCategory === 'erstkraft'" class="editor-container glass">
        <div class="hero-section small">
          <h1 class="main-title">{{ currentErstkraft.id ? 'Erstkraft bearbeiten' : 'Neue Erstkraft' }}</h1>
        </div>

        <div class="editor-grid">
          <div class="input-group">
            <label>Vollständiger Name:</label>
            <input v-model="currentErstkraft.name" type="text" placeholder="z.B. Max Mustermann">
          </div>

          <div class="input-group">
            <label>Kürzel:</label>
            <input v-model="currentErstkraft.kuerzel" type="text" placeholder="z.B. MuM">
          </div>

          <div class="input-row-dual">
            <div class="input-group">
              <label>Hintergrund im Plan:</label>
              <div class="color-picker-wrapper">
                <input type="color" v-model="currentErstkraft.farbe">
              </div>
            </div>

            <div class="input-group">
              <label>Schriftfarbe im Plan:</label>
              <div class="color-picker-wrapper">
                <input type="color" v-model="currentErstkraft.textfarbe">
              </div>
            </div>
          </div>

          <div class="input-group">
            <label>Titel:</label>
            <input v-model="currentErstkraft.titel" type="text" placeholder="z.B. SoKrin">
          </div>

          <div class="input-row-triple">
            <div class="input-group">
              <label>Pflichtstunden</label>
              <div class="custom-number-input">
                <button @click="currentErstkraft.pflichtstunden--">-</button>
                <input v-model.number="currentErstkraft.pflichtstunden" type="number">
                <button @click="currentErstkraft.pflichtstunden++">+</button>
              </div>
            </div>

            <div class="input-group">
              <label>Stundenermäßigung</label>
              <div class="custom-number-input">
                <button @click="currentErstkraft.ermaessigung > 0 ? currentErstkraft.ermaessigung-- : null">-</button>
                <input
                    v-model.number="currentErstkraft.ermaessigung"
                    type="number"
                    min="0"
                    @input="validateErmaessigung"
                >
                <button @click="currentErstkraft.ermaessigung++">+</button>
              </div>
            </div>

            <div class="input-group">
              <label>UPZ</label>
              <div class="custom-number-input">
                <button @click="currentErstkraft.upz > 0 ? currentErstkraft.upz-- : null">-</button>
                <input
                    v-model.number="currentErstkraft.upz"
                    type="number"
                    min="0"
                    @input="validateErmaessigung"
                >
                <button @click="currentErstkraft.upz++">+</button>
              </div>
            </div>
          </div>

          <div class="input-group">
            <label>Unterrichtsfächer, die nicht gelehrt werden (kommasepariert):</label>
            <input v-model="currentErstkraft.faecher" type="text" placeholder="z.B. Religion">
          </div>
        </div>
        <div class="form-footer">
          <button class="glass-btn btn-save-main" @click="saveErstkraft" :disabled="!isErstkraftFormValid"
                  :class="{ 'btn-disabled': !isErstkraftFormValid }">
            <span class="save-icon">💾</span> Erstkraft speichern
          </button>
        </div>
      </div>

      <div v-if="view === 'editor' && activeCategory === 'zweitkraft'" class="editor-container glass">
        <div class="hero-section small">
          <h1 class="main-title">{{ currentZweitkraft.id ? 'Zweitkraft bearbeiten' : 'Neue Zweitkraft' }}</h1>
        </div>

        <div class="editor-grid">
          <div class="input-group">
            <label>Vollständiger Name:</label>
            <input v-model="currentZweitkraft.name" type="text" placeholder="z.B. Maria Muster">
          </div>
          <div class="input-group">
            <label>Kürzel:</label>
            <input v-model="currentZweitkraft.kuerzel" type="text" placeholder="z.B. MaM">
          </div>

          <div class="input-group">
            <label>Beruf / Funktion:</label>
            <div class="custom-select-wrapper">
              <div class="custom-select-trigger" @click.stop="toggleDropdown('zweitkraft_typ')">
                <span>{{ currentZweitkraft.typ || 'Beruf wählen...' }}</span>
                <span class="arrow-down" :class="{ 'rotate': activeDropdown === 'zweitkraft_typ' }">▼</span>
              </div>

              <transition name="fade">
                <div v-if="activeDropdown === 'zweitkraft_typ'" class="custom-options glass">
                  <div
                      v-for="t in ['Kinderpfleger:in','Erzieher:in','Praktikant:in','Individualbegleitung']"
                      :key="t"
                      class="custom-option"
                      :class="{ selected: currentZweitkraft.typ === t }"
                      @click.stop="selectType(t)"
                  >
                    {{ t }}
                  </div>
                </div>
              </transition>
            </div>
          </div>

          <div class="input-row-dual">
            <div class="input-group">
              <label>Hintergrund im Plan:</label>
              <div class="color-picker-wrapper">
                <input type="color" v-model="currentZweitkraft.farbe">
              </div>
            </div>
            <div class="input-group">
              <label>Schriftfarbe im Plan:</label>
              <div class="color-picker-wrapper">
                <input type="color" v-model="currentZweitkraft.textfarbe">
              </div>
            </div>
          </div>

          <div class="input-group full-width">
            <label>Pflichtstundenmaße</label>
            <div v-for="(mass, index) in currentZweitkraft.pflichtstunden_masse" :key="index"
                 class="mass-row-item glass-input">
              <div class="input-row-triple">
                <div class="input-row-dual fifty-percent">
                  <div class="input-group">
                    <label>Pflichtstunden:</label>
                    <div class="custom-number-input">
                      <button @click="mass.stunden--">-</button>
                      <input v-model="mass.stunden" type="number" step="1" min="0">
                      <button @click="mass.stunden++">+</button>
                    </div>
                  </div>

                  <div class="input-group">
                    <label>Planbare Stunden:</label>
                    <div
                        :style="{height: '51px', display: 'flex', alignItems: 'center'}">
                      <span>{{
                          mass.stunden - (currentZweitkraft.ermaessigung / currentZweitkraft.pflichtstunden_masse.length).toFixed(2)
                        }}</span>
                    </div>
                  </div>
                </div>

                <div class="input-row-dual fifty-percent">
                  <div class="input-group">
                    <label>Einsatzort:</label>
                    <input
                        v-model="mass.einsatzort"
                        type="text"
                        placeholder="z.B. IB Schule, HPT"
                        class="glass-input"
                    >
                  </div>
                  <div>
                    <label class="invisible">.</label>
                    <button class="remove-btn" @click="currentZweitkraft.pflichtstunden_masse.splice(index, 1)">×
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <button class="glass-btn btn-add-inline" @click="addPflichtstundenMass">
              <span class="icon">+</span> Pflichtstundenmaß hinzufügen
            </button>
          </div>

          <div class="full-width">
            <div class="input-row-triple">
              <div class="input-group">
                <label>Stundenermäßigung:</label>
                <div class="custom-number-input">
                  <button @click="currentZweitkraft.ermaessigung--">-</button>
                  <input v-model="currentZweitkraft.ermaessigung" type="number" step="1" min="0">
                  <button @click="currentZweitkraft.ermaessigung++">+</button>
                </div>
              </div>
              <div class="input-group">
                <label>Grund für Stundenermäßigung:</label>
                <div class="glass-input">
                  <input v-model="currentZweitkraft.grund_ermaessigung" type="text"
                         placeholder="z.B. Vorbereitungszeit, Team, Elternarbeit">
                </div>
              </div>
              <div class="input-group">
                <label>UPZ:</label>
                <span class="upz-value">{{ calculatedUPZZweitkraft }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="form-footer">
          <button class="glass-btn btn-save-main" :disabled="!isZweitkraftFormValid"
                  :class="{ 'btn-disabled': !isZweitkraftFormValid }" @click="saveZweitkraft">
            <span class="save-icon">💾</span> Zweitkraft speichern
          </button>
        </div>
      </div>

      <div v-if="view === 'editor' && activeCategory === 'raum'" class="editor-container glass">
        <div class="hero-section small">
          <h1 class="main-title">{{ editingRaum.id ? 'Raum bearbeiten' : 'Neuer Raum' }}</h1>
        </div>

        <div class="editor-grid">
          <div class="input-group">
            <label>Raumname / Nummer:</label>
            <input v-model="editingRaum.name" type="text" placeholder="z.B. Musikraum">
          </div>

          <div class="input-group">
            <label>Zugeordnetes Fach (optional):</label>
            <input v-model="editingRaum.unterrichtsfach" type="text" placeholder="z.B. Musik, Werken, Sport">
          </div>

          <div class="input-group full-width">
            <label>Falls nicht immer verfügbar, bitte Verfügbarkeiten angeben:</label>

            <div v-for="(v, index) in editingRaum.verfuegbarkeiten" :key="index" class="termin-card glass">
              <div class="termin-main-row">
                <div class="custom-select-wrapper tag-select">
                  <div class="custom-select-trigger" @click.stop="toggleDropdown('raum-tag-' + index)">
                    <span>{{ v.tag || 'Tag wählen...' }}</span>
                    <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('raum-tag-' + index) }">▼</span>
                  </div>
                  <transition name="fade">
                    <div v-if="isDropdownOpen('raum-tag-' + index)" class="custom-options glass">
                      <div v-for="d in days" :key="d" class="custom-option" @click="v.tag = d; activeDropdown = null">
                        {{ d }}
                      </div>
                    </div>
                  </transition>
                </div>

                <div class="time-col-wrapper">
                  <input v-model="v.startzeit" type="time" class="glass-input time-input">
                  <span class="time-separator">bis</span>
                  <input v-model="v.endzeit" type="time" class="glass-input time-input">
                </div>

                <button class="remove-btn" @click="editingRaum.verfuegbarkeiten.splice(index, 1)">×</button>
              </div>
            </div>

            <button class="glass-btn btn-add-inline" @click="addVerfuegbarkeit">+ Zeitfenster hinzufügen</button>
          </div>
        </div>

        <div class="form-footer">
          <button
              class="glass-btn btn-save-main"
              @click="saveRaum"
              :disabled="!isRaumFormValid"
              :class="{ 'btn-disabled': !isRaumFormValid }"
          >

            <span class="save-icon">💾</span> Raum speichern
          </button>
        </div>
      </div>

      <div v-if="view === 'editor' && activeCategory === 'schulfach'" class="editor-container glass">
        <div class="hero-section small">
          <h1 class="main-title">{{ editingFach.id ? 'Schulfach bearbeiten' : 'Neues Schulfach' }}</h1>
        </div>

        <div class="editor-grid">
          <div class="full-width">
            <div class="input-row-dual">
              <div class="input-group">
                <label>Name des Fachs:</label>
                <input v-model="editingFach.name" type="text" placeholder="z.B. Sport, Schwimmen, Werken">
              </div>
              <div class="input-group">
                <label>Hintergrund im Plan:</label>
                <div class="color-picker-wrapper">
                  <input type="color" v-model="editingFach.farbe">
                </div>
              </div>
            </div>
          </div>

          <div class="input-group full-width">
            <label>Raumanforderungen (optional):</label>

            <div class="raum-grid-selection glass">
              <div
                  v-for="r in raeume"
                  :key="r.id"
                  class="raum-checkbox-item"
                  :class="{ 'active': editingFach.benoetigte_raeume && editingFach.benoetigte_raeume.includes(r.id) }"
                  @click="toggleRaumAnforderung(r.id)"
              >
                <div class="checkbox-box">
                  <span v-if="editingFach.benoetigte_raeume && editingFach.benoetigte_raeume.includes(r.id)">✓</span>
                </div>
                <span class="raum-name-label">{{ r.name }}</span>
              </div>
              <div class="raum-checkbox-item add-new-item" @click.stop="openQuickAddRaumFromFach(index)">
                <span class="new-raum-name-label">+ Raum anlegen</span>
              </div>
            </div>

            <div v-if="raeume.length === 0" class="info-message">
              Keine Räume angelegt. Legen Sie zuerst Räume an, um sie als Anforderung zu definieren.
            </div>
          </div>
        </div>

        <div class="form-footer">
          <button
              class="glass-btn btn-save-main"
              @click="saveFach"
              :disabled="!editingFach.name"
              :class="{ 'btn-disabled': !editingFach.name }"
          >
            <span class="save-icon">💾</span> Fach speichern
          </button>
        </div>
      </div>

      <div v-if="view === 'editor' && activeCategory === 'schuelerstundenplan'" class="timetable-editor-container">
        <div class="timetable-main-content">
          <div class="main-header-plaene">
            <input class="glass-input-class" v-model="currentSchuelerStundenPlan.klasse_name" type="text"
                   placeholder="Klassenbezeichnung...">
            <button
                class="glass-btn btn-save-small"
                @click="saveSchuelerStundenplan"
                :disabled="!currentSchuelerStundenPlan.klasse_name"
                :class="{ 'btn-disabled': !currentSchuelerStundenPlan.klasse_name }"
            >
              <span class="save-icon">💾</span>
            </button>
            <button
                class="glass-btn btn-save-small"
                title="Als Word-Datei exportieren"
                @click="exportSchuelerstundenplan"
                :disabled="!currentSchuelerStundenPlan.id"
                :class="{ 'btn-disabled': !currentSchuelerStundenPlan.id }"
            >
              <span class="save-icon">📄</span>
            </button>
          </div>

          <div class="timetable-scroll-area glass">
            <div class="grid-layout-wrapper">
              <div class="grid-header-row">
                <div class="time-header">Zeit</div>
                <div v-for="tag in days" :key="tag" class="day-header">{{ tag }}</div>
              </div>

              <div class="grid-body">
                <div v-for="stunde in zeitRaster" :key="stunde.id" class="grid-row">

                  <div class="time-label">
                    <div class="time-display" @click="editingId = stunde.id">
                      <div class="time-range">{{ stunde.start }} - {{ stunde.ende }}</div>
                    </div>

                    <div v-if="editingId === stunde.id" class="time-edit-popover">
                      <input type="time" v-model="stunde.start">
                      <span>bis</span>
                      <input type="time" v-model="stunde.ende" @keyup.enter="finishEditing(stunde)">
                      <button class="close-btn" @click="finishEditing(stunde)">✓</button>
                    </div>
                  </div>

                  <div v-for="tag in days" :key="tag"
                       class="grid-cell drop-zone"
                       :class="{ 'drag-over': dragOverCell === `${tag}-${stunde.id}` }"
                       @dragover.prevent="dragOverCell = `${tag}-${stunde.id}`"
                       @dragleave="dragOverCell = null"
                       @drop="handleDrop($event, tag, stunde)">

                    <div class="chip-container">
                      <transition-group name="pop">
                        <div v-for="termin in getAssignment(tag, stunde)"
                             :key="termin.uuid || termin.id"
                             class="subject-chip"
                             draggable="true"
                             @dragstart="handleMoveStart($event, termin)"
                             @dragend="handleMoveEnd($event)"
                             @click="editAssignment(termin)"
                             :style="{cursor: 'pointer',
                                backgroundColor: (termin.display && termin.display.farbe) || termin.farbe || '#e0e0e0'
                             }">
                          <div class="chip-info">
                            <strong class="truncate-text">{{ termin['display']['fachName'] }}</strong>
                            <small>{{ termin['display']['lehrerKuerzel'] }}</small>
                          </div>
                        </div>
                      </transition-group>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="bottom-box">
            <div class="toolbox-bottom glass">
              <div class="subject-scroll-container">
                <div v-for="fach in verfuegbareFaecher"
                     :key="fach.id"
                     class="draggable-subject"
                     :class="{ 'is-dragging': draggingId === fach.id }"
                     draggable="true"
                     @dragstart="handleDragStart($event, fach)"
                     @dragend="handleDragEnd"
                     @click="showStatus('Schulfächer in den Plan ziehen')"
                     :style="{ backgroundColor: fach.farbe || getRandomPastelColor(fach.name) }">
                  {{ fach.name }}
                </div>
                <button class="btn-add white-text" @click="openQuickAdd('fach')">+ Fach</button>
              </div>
            </div>
          </div>
        </div>

        <aside class="timetable-sidebar glass">
          <div class="sidebar-header">
            <h3>Stundentafel</h3>
            <br>
            <div class="tafel-meta">
              <span>Fach</span>
              <div class="badges">
                <label>Verbund |</label>
                <label>Diff. |</label>
                <label>Soll</label>
              </div>
            </div>
          </div>

          <div class="stundentafel-list">
            <div v-for="item in dynamicStundentafel" :key="item.name" class="tafel-card">
              <div class="tafel-meta">
                <div class="tafel-header-row" @click="addNewTafelItem(item)">
                  <span class="fach-title" :data-tooltip="item.name">{{ item.name }}</span>
                  <div class="badges">
                    <div class="fach-stats-badge"
                         :style="{
                       backgroundColor: item.ist_klassenverbund === item.soll_klassenverbund ? '#193217' : item.ist_klassenverbund > item.soll_klassenverbund ? '#3e2023' : '#474322'}">
                      <span class="ist-val">{{ item.ist_klassenverbund }}</span>
                      <span class="sep">/</span>
                      <span class="soll-val">{{ item.soll_klassenverbund }}</span>
                    </div>
                    <div class="fach-stats-badge"
                         :style="{
                       backgroundColor: item.ist_differenzierung === item.soll_differenzierung ? '#193217' : item.ist_differenzierung > item.soll_differenzierung ? '#3e2023' : '#474322'}">
                      <span class="ist-val">{{ item.ist_differenzierung }}</span>
                      <span class="sep">/</span>
                      <span class="soll-val">{{ item.soll_differenzierung }}</span>
                    </div>
                    <div class="fach-stats-badge"
                         :style="{
                       backgroundColor: (item.ist_klassenverbund + item.ist_differenzierung) === (item.soll_differenzierung + item.soll_klassenverbund) ? '#193217' :
                       (item.ist_klassenverbund+item.ist_differenzierung) > (item.soll_differenzierung + item.soll_klassenverbund) ? '#3e2023' : '#474322'}">
                      <span class="soll-val">{{ item.soll_differenzierung + item.soll_klassenverbund }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="total-stats-footer glass" v-if="dynamicStundentafel.length > 0">
              <div class="stats-header">
                <span class="stats-label">Gesamt</span>
                <div class="stats-badges">
                  <span class="badge ist">Ist: {{ totalIst }}</span>
                  <span class="badge soll">Soll: {{ totalSoll }}</span>
                </div>
              </div>

              <div class="total-progress-container">
                <div class="total-progress-bar">
                  <div class="total-progress-fill"
                       :style="{
               width: Math.min((totalIst / totalSoll) * 100, 100) + '%',
               backgroundColor: totalIst > totalSoll ? '#ff4757' : '#2ed573'
             }">
                  </div>
                </div>
                <div class="total-percentage">
                  {{ totalSoll > 0 ? Math.round((totalIst / totalSoll) * 100) : 0 }}% erfüllt
                </div>
              </div>
            </div>

            <button @click="addNewTafelItem" class="add-fach-btn">
              + Soll-Stunden hinzufügen
            </button>
          </div>
        </aside>
      </div>

      <div v-if="view === 'editor' && activeCategory === 'raumbelegungsplan'" class="timetable-editor-container">
        <div class="room-timetable-main-content">
          <div class="main-header-plaene" v-if="raumVerfuegbarkeiten.find(r => r.id === activeRaumId)?.name">
            <span class="raum-plaene-head">{{ raumVerfuegbarkeiten.find(r => r.id === activeRaumId)?.name }}</span>
            <button
                class="glass-btn btn-save-small"
                title="Als Word-Datei exportieren"
                @click="exportRaumbelegungsplan"
            >
              <span class="save-icon">📄</span>
            </button>
          </div>

          <div class="timetable-scroll-area glass">
            <div class="grid-header-row">
              <div class="room-time-header">Zeit</div>
              <div v-for="tag in days" :key="tag" class="room-day-header">{{ tag }}</div>
            </div>

            <div class="room-grid-layout-wrapper">
              <div class="grid-body">
                <div v-for="stunde in raumZeitRaster" :key="stunde.id" class="grid-row">

                  <div class="room-time-label">
                    <div class="room-time-range">{{ stunde.start }} - {{ stunde.ende }}</div>
                  </div>
                </div>
              </div>

              <div v-for="tag in days" :key="tag" class="day-column">

                <div
                    v-for="(termin, index) in (raumVerfuegbarkeiten.find(r => r.id === activeRaumId)?.termine || []).filter(t => t.tag === tag)"
                    :key="termin.id + '-' + index"
                    class="subject-chip-absolute"
                    :style="getTerminStyle(termin)">

                  <div class="chip-info">
                    <strong>{{
                        termin.fach_id ? faecher.find(r => r.id === termin.fach_id)?.name : aktivitaeten.find(a => a.id === termin.aktivitaet_id)?.name
                      }}</strong>
                    <small>{{ termin.start.slice(0, 5) }} - {{ termin.ende.slice(0, 5) }}</small>
                    <small>{{ lehrerVerfuegbarkeiten.find(r => r.id === termin.termin_id)?.klassen_name }}</small>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="view === 'editor' && activeCategory === 'lehrerstundenplan'" class="timetable-editor-container">
        <div class="room-timetable-main-content">
          <div class="main-header-plaene">
            <div class="raum-plaene-head">
              {{ currentLehrerstundenplan.name }}
            </div>
            <button
                class="glass-btn btn-save-small"
                @click="saveLehrerStundenplan"
            >
              <span class="save-icon">💾</span>
            </button>
            <button
                class="glass-btn btn-save-small"
                title="Als Word-Datei exportieren"
                @click="exportLehrerstundenplan"
            >
              <span class="save-icon">📄</span>
            </button>
          </div>

          <div class="lehrer-timetable-scroll-area glass">
            <div class="grid-header-row">
              <div class="room-time-header">Zeit</div>
              <div v-for="tag in days" :key="tag" class="room-day-header">{{ tag }}</div>
            </div>

            <div class="room-grid-layout-wrapper">
              <div class="grid-body">
                <div v-for="stunde in raumZeitRaster" :key="stunde.id" class="grid-row">

                  <div class="room-time-label">
                    <div class="room-time-range">{{ stunde.start }} - {{ stunde.ende }}</div>
                  </div>
                </div>
              </div>

              <div v-for="tag in days" :key="tag" class="day-column"
                   :class="{ 'drag-over': dragOverCell === `${tag}` }"
                   @dragover.prevent="dragOverCell = `${tag}`"
                   @dragleave="dragOverCell = null"
                   @drop="handleLehrerDrop($event, tag)">

                <div
                    v-for="termin in currentLehrerstundenplan.termine.filter(t => t.tag === tag)"
                    :key="termin.id"
                    class="subject-chip-absolute"
                    :style="getTerminStyleExact(termin)"
                    draggable="true"
                    @dragstart="handleLehrerMoveStart($event, termin)"
                    @dragend="handleLehrerMoveEnd($event)"
                    @click="editLehrerAssignment(termin)">

                  <div class="chip-info">
                    <strong>{{ termin.fach || termin.aktivitaet }}</strong>
                    <small>{{ termin.klasse }}</small>
                    <small>{{ termin.start.slice(0, 5) }} - {{ termin.ende.slice(0, 5) }}</small>
                  </div>
                </div>
              </div>
            </div>
          </div>


          <div class="bottom-box">
            <div class="toolbox-bottom glass">
              <div class="subject-scroll-container">
                <div v-for="fach in verfuegbareFaecher"
                     :key="fach.id"
                     class="draggable-subject"
                     :class="{ 'is-dragging': draggingId === fach.id }"
                     draggable="true"
                     @dragstart="handleLehrerDragStart($event, fach, 'f')"
                     @dragend="handleDragEnd"
                     @click="showStatus('Schulfächer in den Plan ziehen')"
                     :style="{ backgroundColor: fach.farbe}">
                  {{ fach.name }}
                </div>
                <div v-for="a in aktivitaetenMitFarbe"
                     :key="a.id"
                     class="draggable-subject-activity"
                     :class="{ 'is-dragging': draggingId === a.id }"
                     draggable="true"
                     @dragstart="handleLehrerDragStart($event, a, 'a')"
                     @dragend="handleDragEnd"
                     @click="showStatus('Aktivitäten in den Plan ziehen')"
                     :style="{borderColor: a.farbe, color: a.farbe}">
                  {{ a.name }}
                </div>
                <button class="btn-add white-text lehrerplan-btn-add" @click="openQuickAdd('fach')">+ Fach</button>
                <button class="btn-add white-text" @click="openQuickAdd('aktivitaet')">+ Aktivität</button>
              </div>
            </div>
          </div>
        </div>

        <aside class="timetable-sidebar glass">
          <div class="sidebar-header">
            <h3>Stundentafel in h</h3>
            <br>
            <div class="tafel-meta">
              <span>Fach</span>
              <div class="badges">
                <label>Verbund |</label>
                <label>Diff. |</label>
                <label>Soll</label>
              </div>
            </div>
          </div>

          <div class="stundentafel-list">
            <div v-for="item in dynamicLehrerstundentafel" :key="item.name" class="tafel-card">
              <div class="tafel-meta">
                <div class="tafel-header-row" @click="editTafelEintrag(item)" :data-tooltip="item.name">
                  <span class="fach-title">{{ item.name }}</span>
                  <div class="badges">
                    <div class="fach-stats-badge"
                         :style="{
                       backgroundColor: item.ist_klassenverbund === item.soll_klassenverbund ? '#193217' : item.ist_klassenverbund > item.soll_klassenverbund ? '#3e2023' : '#474322'}">
                      <span class="ist-val">{{ item.ist_klassenverbund }}</span>
                      <span class="sep">/</span>
                      <span class="soll-val">{{ item.soll_klassenverbund }}</span>
                    </div>
                    <div class="fach-stats-badge"
                         :style="{
                       backgroundColor: item.ist_differenzierung === item.soll_differenzierung ? '#193217' : item.ist_differenzierung > item.soll_differenzierung ? '#3e2023' : '#474322'}">
                      <span class="ist-val">{{ item.ist_differenzierung }}</span>
                      <span class="sep">/</span>
                      <span class="soll-val">{{ item.soll_differenzierung }}</span>
                    </div>
                    <div class="fach-stats-badge"
                         :style="{
                       backgroundColor: (item.ist_klassenverbund + item.ist_differenzierung) === (item.soll_differenzierung + item.soll_klassenverbund) ? '#193217' :
                       (item.ist_klassenverbund+item.ist_differenzierung) > (item.soll_differenzierung + item.soll_klassenverbund) ? '#3e2023' : '#474322'}">
                      <span class="soll-val">{{ item.soll_differenzierung + item.soll_klassenverbund }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="total-stats-footer glass" v-if="dynamicLehrerstundentafel.length > 0">
              <div class="stats-header">
                <span class="stats-label">Gesamt</span>
                <div class="stats-badges">
                  <span class="badge ist">Ist: {{ totalIst_Lehrer }}</span>
                  <span class="badge soll">Soll: {{ totalSoll_Lehrer }}</span>
                </div>
              </div>

              <div class="total-progress-container">
                <div class="total-progress-bar">
                  <div class="total-progress-fill"
                       :style="{
               width: Math.min((totalIst_Lehrer / totalSoll_Lehrer) * 100, 100) + '%',
               backgroundColor: totalIst_Lehrer > totalSoll_Lehrer ? '#ff4757' : '#2ed573'
             }">
                  </div>
                </div>
                <span class="badge soll" :style="{display: 'flex', alignItem: 'center', justifyContent: 'center',
                  backgroundColor: totalIst_Lehrer > currentLehrerstundenplan.upz ? '#ff4757' : totalIst_Lehrer === currentLehrerstundenplan.upz ? '#2ed573' : '#999999',
                  color: totalIst_Lehrer > currentLehrerstundenplan.upz ? '#200000' : totalIst_Lehrer === currentLehrerstundenplan.upz ? '#002301' : '#111111'}">
                  UPZ: {{ currentLehrerstundenplan.upz }}</span>
              </div>
            </div>

            <button @click="addNewTafelItem" class="add-fach-btn">
              + Soll-Stunden hinzufügen
            </button>
          </div>
        </aside>
      </div>

      <div v-if="view === 'editor' && activeCategory === 'diensteinsatzplan'" class="timetable-editor-container">
        <div class="room-timetable-main-content">
          <div class="main-header-plaene">
            <div class="raum-plaene-head">
              {{ currentDiensteinsatzplan.name }}
            </div>
            <button
                class="glass-btn btn-save-small"
                @click="saveDiensteinsatzplan"
            >
              <span class="save-icon">💾</span>
            </button>
            <button
                class="glass-btn btn-save-small"
                title="Als Word-Datei exportieren"
                @click="exportDiensteinsatzplan"
            >
              <span class="save-icon">📄</span>
            </button>
          </div>

          <div class="lehrer-timetable-scroll-area glass">
            <div class="grid-header-row">
              <div class="room-time-header">Zeit</div>
              <div v-for="tag in days" :key="tag" class="room-day-header">{{ tag }}</div>
            </div>

            <div class="room-grid-layout-wrapper">
              <div class="grid-body">
                <div v-for="stunde in raumZeitRaster" :key="stunde.id" class="grid-row">

                  <div class="room-time-label">
                    <div class="room-time-range">{{ stunde.start }} - {{ stunde.ende }}</div>
                  </div>
                </div>
              </div>

              <div v-for="tag in days" :key="tag" class="day-column"
                   :class="{ 'drag-over': dragOverCell === `${tag}` }"
                   @dragover.prevent="dragOverCell = `${tag}`"
                   @dragleave="dragOverCell = null"
                   @drop="handleLehrerDrop($event, tag)">

                <div
                    v-for="termin in (currentDiensteinsatzplan.termine || []).filter(t => t.tag === tag)"
                    :key="termin.termin_id"
                    class="subject-chip-absolute"
                    :style="getDienstTerminStyle(termin)"
                    draggable="true"
                    @dragstart="handleLehrerMoveStart($event, termin)"
                    @dragend="handleLehrerMoveEnd($event)"
                    @click="editLehrerAssignment(termin)">

                  <div class="chip-info">
                    <strong>{{ termin.aktivitaet }}</strong>
                    <small v-if="termin.einsatzort">📍 {{ termin.einsatzort }}</small>
                    <small v-if="termin.klasse">{{ termin.klasse }}</small>
                    <small>{{ termin.start.slice(0, 5) }} - {{ termin.ende.slice(0, 5) }}</small>
                  </div>
                </div>
              </div>
            </div>
          </div>


          <div class="bottom-box">
            <div class="toolbox-bottom glass">
              <div class="subject-scroll-container">
                <div v-for="a in aktivitaetenMitFarbe"
                     :key="a.id"
                     class="draggable-subject-activity"
                     :class="{ 'is-dragging': draggingId === a.id }"
                     draggable="true"
                     @dragstart="handleLehrerDragStart($event, a, 'a')"
                     @dragend="handleDragEnd"
                     @click="showStatus('Aktivitäten in den Plan ziehen')"
                     :style="{borderColor: a.farbe, color: a.farbe}">
                  {{ a.name }}
                </div>
                <button class="btn-add white-text" @click="openQuickAdd('aktivitaet')">+ Aktivität</button>
              </div>
            </div>
          </div>
        </div>

        <aside class="timetable-sidebar glass">
          <div class="sidebar-header">
            <h3>Stundentafel in h</h3>
            <br>
            <div class="tafel-meta">
              <span>Aktivität</span>
              <div class="badges">
                <label>Ist / Soll</label>
              </div>
            </div>
          </div>

          <div class="stundentafel-list">
            <div v-for="item in dynamicDiensteinsatztafel" :key="item.name" class="tafel-card">
              <div class="tafel-meta">
                <div class="tafel-header-row dienst-tafel-row" :data-tooltip="item.tooltip">
                  <span class="fach-title">{{ item.name }}</span>
                  <div class="badges">
                    <div class="fach-stats-badge" :style="{ backgroundColor: dienstBadgeColor(item.ist, item.soll) }">
                      <span class="ist-val">{{ item.ist }}</span>
                      <span class="sep">/</span>
                      <span class="soll-val">{{ item.soll }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="total-stats-footer glass" v-if="dynamicDiensteinsatztafel.length > 0">
              <div class="stats-header">
                <span class="stats-label">Gesamt</span>
                <div class="stats-badges">
                  <span class="badge ist">Ist: {{ totalIst_Dienst }}</span>
                  <span class="badge soll">Soll: {{ totalSoll_Dienst }}</span>
                </div>
              </div>

              <div class="total-progress-container">
                <div class="total-progress-bar">
                  <div class="total-progress-fill"
                       :style="{
               width: Math.min((totalIst_Dienst / totalSoll_Dienst) * 100, 100) + '%',
               backgroundColor: totalIst_Dienst > totalSoll_Dienst ? '#ff4757' : '#2ed573'
             }">
                  </div>
                </div>
                <span class="badge soll" :style="{display: 'flex', alignItem: 'center', justifyContent: 'center',
                  backgroundColor: totalIst_Dienst > currentDiensteinsatzplan.upz ? '#ff4757' : totalIst_Dienst === currentDiensteinsatzplan.upz ? '#2ed573' : '#999999',
                  color: totalIst_Dienst > currentDiensteinsatzplan.upz ? '#200000' : totalIst_Dienst === currentDiensteinsatzplan.upz ? '#002301' : '#111111'}">
                  UPZ: {{ currentDiensteinsatzplan.upz }}</span>
              </div>
            </div>
          </div>
        </aside>
      </div>

      <transition name="fade">
        <div v-if="showRaumModal" class="modal-overlay" @click.self="showRaumModal = false">
          <div class="modal-content glass-modal">
            <div class="modal-header">
              <h3><span class="icon">🏫</span> Neuen Raum anlegen</h3>
              <button class="close-btn-circle" @click="showRaumModal = false">×</button>
            </div>

            <div class="modal-body">
              <div class="input-floating-group">
                <label>Raumname</label>
                <input v-model="editingRaum.name" placeholder="z.B. Turnhalle" class="glass-input-large">
              </div>

              <div class="input-floating-group">
                <label>Unterrichtsfach / Info</label>
                <input v-model="editingRaum.unterrichtsfach" placeholder="z.B. Sport" class="glass-input-large">
              </div>

              <div class="availability-toggle-box">
                <div class="toggle-text">
                  <strong>Immer verfügbar?</strong>
                  <span>Gilt für alle Wochentage rund um die Uhr</span>
                </div>
                <label class="switch">
                  <input type="checkbox" v-model="editingRaum.immer_verfuegbar">
                  <span class="slider round"></span>
                </label>
              </div>

              <transition name="fade">
                <div v-if="!editingRaum.immer_verfuegbar" class="extra-section">
                  <div class="section-title">Verfügbarkeit</div>

                  <div v-for="(v, vIndex) in editingRaum.verfuegbarkeiten" :key="vIndex"
                       class="availability-row-card glass">
                    <div class="custom-select-wrapper tag-select">
                      <div class="custom-select-trigger" @click.stop="toggleDropdown('raum-modal-tag-' + vIndex)">
                        <span>{{ v.tag || 'Tag wählen...' }}</span>
                        <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('raum-modal-tag-' + vIndex) }">▼</span>
                      </div>
                      <transition name="fade">
                        <div v-if="isDropdownOpen('raum-modal-tag-' + vIndex)" class="custom-options glass">
                          <div v-for="d in days" :key="d" class="custom-option" @click="v.tag = d; activeDropdown = null">
                            {{ d }}
                          </div>
                        </div>
                      </transition>
                    </div>
                    <div class="time-inputs">
                      <input type="time" v-model="v.startzeit">
                      <span>bis</span>
                      <input type="time" v-model="v.endzeit">
                      <button class="remove-btn" @click="editingRaum.verfuegbarkeiten.splice(vIndex, 1)">×</button>
                    </div>
                  </div>

                  <button class="btn-ghost-add" @click="addZeitfenster">
                    <span>+</span> Zeitfenster hinzufügen
                  </button>
                </div>
              </transition>
            </div>

            <div class="modal-footer">
              <button class="glass-btn-cancel" @click="showRaumModal = false">Abbrechen</button>
              <button class="glass-btn-save" :disabled="!editingRaum.name" @click="saveRaum">
                Raum speichern
              </button>
            </div>
          </div>
        </div>
      </transition>

      <transition name="fade">
        <div v-if="showPersonModal" class="modal-overlay" @click.self="showPersonModal = false">
          <div class="modal-content glass-modal">
            <div class="modal-header">
              <h3>
                <span class="icon">👤</span>
                {{ editingPerson.type === 'erstkraft' ? 'Neue Erstkraft' : 'Neue Zweitkraft' }}
              </h3>
              <button class="close-btn-circle" @click="showPersonModal = false">×</button>
            </div>

            <div class="modal-body">
              <div class="input-floating-group">
                <label>Vollständiger Name</label>
                <input v-model="editingPerson.name" placeholder="z.B. Max Mustermann" class="glass-input-large"
                       @keyup.enter="savePerson">
              </div>
              <div class="input-group">
                <label>Kürzel (max. 4 Zeichen):</label>
                <input
                    v-model="editingPerson.kuerzel"
                    placeholder="MaM"
                    class="glass-input kuerzel-input"
                    maxlength="5"
                >
              </div>
              <p class="hint-text-small">Die Person wird direkt für diesen Termin ausgewählt.</p>
            </div>

            <div class="modal-footer">
              <button class="glass-btn-cancel" @click="showPersonModal = false">Abbrechen</button>
              <button class="glass-btn-save" :disabled="!editingPerson.name" @click="savePerson">
                Person speichern
              </button>
            </div>
          </div>
        </div>
      </transition>

      <transition name="fade">
        <div v-if="showLehrerModal" class="modal-overlay" @click.self="cancelAssignment">
          <div class="modal-content glass staff-selection-modal">
            <div class="modal-header">
              <h2>Erstkraft auswählen</h2>
              <p class="subtitle">{{ pendingAssignment?.fachName }} am {{ pendingAssignment?.tag }}</p>
            </div>

            <div class="staff-grid-container">
              <div v-for="lehrer in erstkraefte"
                   :key="lehrer.id"
                   class="staff-box glass-card"
                   @click="confirmAssignment(lehrer)">
                <div class="staff-avatar"
                     :style="{ background: `linear-gradient(135deg, ${lehrer.farbe} 10%, rgba(255,255,255,0.4) 80%)` }">
                  {{ lehrer.kuerzel }}
                </div>
                <div class="staff-name">{{ lehrer.name }}</div>
              </div>

              <div class="staff-box add-new-card glass-card" @click="addNewLehrer">
                <div class="staff-avatar"
                     :style="{ background: `linear-gradient(135deg, ${getRandomDarkColor()} 10%, rgba(255,255,255,0.4) 80%)` }">
                  +
                </div>
                <div class="staff-name">Neu anlegen</div>
              </div>
            </div>

            <div class="modal-footer">
              <button class="btn-cancel" @click="cancelAssignment">Abbrechen</button>
            </div>
          </div>
        </div>
      </transition>

      <transition name="fade">
        <div v-if="showNewFachModal" class="modal-overlay" @click.self="showNewFachModal = false">
          <div class="modal-content glass-modal">
            <div class="modal-header">
              <h3><span class="icon">📚</span> Neues Schulfach</h3>
              <button class="close-btn-circle" @click="showNewFachModal=false">×</button>
            </div>

            <div class="modal-body">
              <div class="input-floating-group">
                <label>Name des Fachs:</label>
                <input v-model="editingFach.name" placeholder="z.B. GU" class="glass-input-large"
                       @keyup.enter="saveFach">
              </div>

              <div class="input-group">
                <label>Farbe des Fachs:</label>
                <div class="color-picker-container">
                  <input type="color" v-model="editingFach.farbe" class="glass-color-input">
                </div>
              </div>

              <div class="input-group">
                <label>Benötigte Spezialräume:</label>
                <div class="raum-grid-selection glass">
                  <div
                      v-for="r in raeume"
                      :key="r.id"
                      class="raum-checkbox-item"
                      :class="{ 'active': editingFach.benoetigte_raeume.includes(r.id) }"
                      @click="toggleRaumAnforderung(r.id)"
                  >
                    <div class="checkbox-box">
                      <span v-if="editingFach.benoetigte_raeume.includes(r.id)">✓</span>
                    </div>
                    <span class="raum-name-label">{{ r.name }}</span>
                  </div>
                  <div class="raum-checkbox-item add-new-item" @click.stop="openQuickAdd('raum')">
                    <span class="new-raum-name-label">+ Raum anlegen</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal-footer">
              <button class="glass-btn-save" :disabled="!editingFach.name" @click="saveFach">
                Fach speichern
              </button>
            </div>
          </div>
        </div>
      </transition>

      <transition name="fade">
        <div v-if="showTafelSelectionModal" class="modal-overlay" @click.self="showTafelSelectionModal = false">
          <div class="modal-content glass-modal">
            <div class="modal-header">
              <h3><span class="icon">📚</span>Stundentafel erweitern</h3>
              <button class="close-btn-circle" @click="showTafelSelectionModal = false">×</button>
            </div>

            <div class="modal-body">
              <div class="input-group">
                <label>Schulfach auswählen:</label>
                <div class="staff-grid-container stundentafel-grid">
                  <div v-for="fach in faecher"
                       :key="'f-' + fach.id"
                       class="staff-box glass-card"
                       :class="{ 'active-selection': selectedUniqueKey === 'f-' + fach.id }"
                       @click="selectFachForTafel(fach, 'f')">
                    <div class="staff-avatar"
                         :style="{ background: `linear-gradient(135deg, ${fach.farbe} 10%, rgba(255,255,255,0.4) 80%)` }">
                      {{ fach.name.substring(0, 2).toUpperCase() }}
                    </div>
                    <div class="staff-name">{{ fach.name }}</div>
                  </div>
                  <template v-if="activeCategory === 'lehrerstundenplan'">
                    <div v-for="a in aktivitaeten"
                         :key="'a-' + a.id"
                         class="staff-box glass-card"
                         :class="{ 'active-selection': selectedUniqueKey === 'a-' + a.id }"
                         @click="selectFachForTafel(a, 'a')">
                      <div class="staff-avatar"
                           :style="{ background: `linear-gradient(135deg, ${a.farbe} 10%, rgba(255,255,255,0.4) 80%)` }">
                        {{ a.name.substring(0, 2).toUpperCase() }}
                      </div>
                      <div class="staff-name">{{ a.name }}</div>
                    </div>
                  </template>

                  <div class="staff-box add-new-card glass-card" @click="openNewFachModalFromTafel">
                    <div class="staff-avatar">+</div>
                    <div class="staff-name">Neu</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal-body" v-if="selectedFachId">
              <template v-if="activeCategory === 'schuelerstundenplan'">
                <div class="slider-group">
                  <div class="zeile">
                    <label>Soll-Stunden Klassenverbund:</label>
                    <div class="slider-head">
                        <span class="units" :class="{ 'text-success': newTafelEntry.soll_klassenverbund > 0 }">
                          {{ newTafelEntry.soll_klassenverbund }} Einheiten
                        </span>
                      <span class="arrow">➔</span>
                      <span class="real-time">{{
                          formattedSchuelerSliderTime(newTafelEntry.soll_klassenverbund)
                        }}</span>
                    </div>
                  </div>
                  <div class="slider-container">
                    <input type="range" min="0" max="20" step="1" v-model="newTafelEntry.soll_klassenverbund"
                           class="custom-slider">
                  </div>
                </div>
                <div class="slider-labels">
                  <span>0</span>
                  <span>10</span>
                  <span>20</span>
                </div>
                <div class="slider-group">
                  <div class="zeile">
                    <label>Soll-Stunden äußere Differenzierung: </label>
                    <div class="slider-head">
                      <span class="units">{{
                          newTafelEntry.soll_differenzierung
                        }} Einheiten</span>
                      <span class="arrow">➔</span>
                      <span class="real-time">{{
                          formattedSchuelerSliderTime(newTafelEntry.soll_differenzierung)
                        }}</span>
                    </div>
                  </div>
                  <div class="slider-container">
                    <input type="range" min="0" max="20" step="1" v-model="newTafelEntry.soll_differenzierung"
                           class="custom-slider">
                    <div class="slider-labels"><span>0</span><span>10</span><span>20</span></div>
                  </div>
                </div>
              </template>

              <template v-if="selectedType === 'f' && activeCategory === 'lehrerstundenplan'">
                <div class="slider-group">
                  <div class="zeile">
                    <label>Soll-Stunden Klassenverbund:</label>
                    <div class="slider-head">
                        <span class="units" :class="{ 'text-success': einheiten_kv > 0 }">
                          {{ einheiten_kv }} Einheiten
                        </span>
                      <span class="arrow">➔</span>
                      <span class="real-time">{{ formattedSliderTime(get_soll_klassenverbund) }}</span>
                    </div>
                  </div>
                  <div class="slider-container">
                    <input type="range" min="0" max="20" step="1" v-model.number="einheiten_kv"
                           class="custom-slider">
                  </div>
                </div>
                <div class="slider-labels">
                  <span>0</span>
                  <span>10</span>
                  <span>20</span>
                </div>
                <div class="slider-group">
                  <div class="zeile">
                    <label>Soll-Stunden äußere Differenzierung: </label>
                    <div class="slider-head">
                      <span class="units">{{
                          einheiten_diff
                        }} Einheiten</span>
                      <span class="arrow">➔</span>
                      <span class="real-time">{{ formattedSliderTime(get_soll_differenzierung) }}</span>
                    </div>
                  </div>
                  <div class="slider-container">
                    <input type="range" min="0" max="20" step="1" v-model="einheiten_diff"
                           class="custom-slider">
                    <div class="slider-labels"><span>0</span><span>10</span><span>20</span></div>
                  </div>
                </div>
              </template>

              <template v-else-if="selectedType === 'a' && activeCategory === 'lehrerstundenplan'">
                <div class="input-row-dual" :style="{display: 'flex'}">
                  <div class="time-input-container" :style="{display: 'flex', flexDirection: 'column' , width: '50%'}">
                    <label>Soll-Stunden Klassenverbund:</label>
                    <div class="duration-picker">
                      <div class="time-input-field">
                        <input type="number" v-model="newTafelEntry.soll_stunden_klassenverbund" min="0"
                               placeholder="0">
                        <span>Std</span>
                      </div>
                      <div class="time-input-divider">:</div>
                      <div class="time-input-field">
                        <input type="number" v-model="newTafelEntry.soll_minuten_klassenverbund" min="0" max="59"
                               placeholder="00">
                        <span>Min</span>
                      </div>
                    </div>
                  </div>
                  <div class="time-input-container" :style="{display: 'flex', flexDirection: 'column' , width: '50%'}">
                    <label>Soll-Stunden äußere Differenzierung:</label>
                    <div class="duration-picker">
                      <div class="time-input-field">
                        <input type="number" v-model="newTafelEntry.soll_stunden_differenzierung" min="0"
                               placeholder="0">
                        <span>Std</span>
                      </div>
                      <div class="time-input-divider">:</div>
                      <div class="time-input-field">
                        <input type="number" v-model="newTafelEntry.soll_minuten_differenzierung" min="0" max="59"
                               placeholder="00">
                        <span>Min</span>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
            </div>


            <div class="modal-footer">
              <button class="glass-btn-save"
                      :disabled="!selectedFachId"
                      @click="saveFachToStundentafel">
                Speichern
              </button>
            </div>
          </div>
        </div>
      </transition>

      <div v-if="showImportPreview" class="modal-overlay">
        <div class="modal-content glass import-preview-modal">
          <h2>Dokumenten-Vorschau</h2>
          <p>Der extrahierte Text aus der Datei:</p>

          <div class="import-list">
            <div v-for="(res, index) in importResults" :key="index" class="import-item">
              <div class="file-header">
                <strong>Datei: {{ res.file }}</strong>
              </div>

              <div v-if="res.error" class="error-msg">{{ res.error }}</div>

              <div v-else class="raw-text-container">
          <textarea
              readonly
              class="glass-input raw-text-display"
              v-model="res.rawText"
          ></textarea>
              </div>
            </div>
          </div>

          <div class="modal-buttons">
            <button class="glass-btn secondary" @click="showImportPreview = false">Schließen</button>
            <button class="glass-btn primary" @click="confirmImport">Weiter zum Parsen</button>
          </div>
        </div>
      </div>

      <div v-if="showOnboardingModal" class="modal-overlay" style="z-index: 9999">
        <div class="modal-content glass-modal">
          <div class="modal-header">
            <h3><span class="icon">🎓</span> Willkommen bei elli</h3>
          </div>
          <div class="modal-body">
            <p style="margin-bottom: 1rem; line-height: 1.5;">
              Um loszulegen, muss zuerst ein <strong>Schuljahr</strong> angelegt werden.
              Ohne Schuljahr lassen sich keine Klassen, Lehrkräfte oder Pläne erstellen.
            </p>
            <div class="input-floating-group">
              <label>Schuljahr:</label>
              <input v-model="onboarding.schuljahr" placeholder="z.B. 25/26" class="glass-input-large"
                     @keyup.enter="createFirstSchuljahr">
            </div>
            <div class="input-floating-group">
              <label>Name der Schule (optional):</label>
              <input v-model="onboarding.schulname" placeholder="z.B. Grundschule Musterhausen"
                     class="glass-input-large" @keyup.enter="createFirstSchuljahr">
            </div>
          </div>
          <div class="modal-footer">
            <button class="glass-btn-save" :disabled="!onboarding.schuljahr.trim() || onboardingSaving"
                    @click="createFirstSchuljahr">
              {{ onboardingSaving ? 'Wird angelegt…' : "Los geht's" }}
            </button>
          </div>
        </div>
      </div>

    </main>
    <transition name="slide-up">
      <div v-if="snackbar.show" class="snackbar" :class="snackbar.type">
        {{ snackbar.message }}
      </div>
    </transition>

    <transition name="bounce">
      <div
          v-if="isDragging"
          class="trash-zone glass"
          @dragover.prevent
          @drop="handleDeleteDrop($event)"
          :class="{ 'trash-active': isOverTrash }"
          @dragenter="isOverTrash = true"
          @dragleave="isOverTrash = false"
      >
        <div class="trash-icon">🗑️</div>
        <span>Zum Löschen hierher ziehen</span>
      </div>
    </transition>
  </div>

  <transition name="fade">
    <div v-if="showLehrerPlanModal" class="modal-overlay" @click="showLehrerPlanModal = false">
      <div class="modal-content glass-modal" @click.stop>

        <div class="modal-header">
          <h3><span class="icon">📅</span> Eintrag für {{ lehrerPlanForm.tag }}</h3>
          <button class="close-btn-circle" @click="resetLehrerForm">×</button>
        </div>

        <div class="modal-body">
          <div class="input-row-triple">
            <div class="input-group" style="display: flex; flex-direction: column;">
              <div class="zeile lehrerstundenplan" style="display: flex; align-items: center; min-height: 32px;">
                <label>Klasse:</label>
                <button v-if="!isNewKlasse" class="text-btn-tiny" @click="isNewKlasse = true">+ Neue Klasse</button>
                <button v-else class="text-btn-tiny" @click="isNewKlasse = false">Zurück zur Liste</button>
              </div>

              <div v-if="!isNewKlasse" class="custom-select-wrapper" style="position: relative;">
                <div class="custom-select-trigger glass-input-large" @click.stop="toggleLehrerDropdown('klasse')">
              <span v-if="lehrerPlanForm.klassen_id || lehrerPlanForm.klasse" class="selected-text-active">
                {{ lehrerPlanForm.klasse || getSelectedKlassenName }}
              </span>
                  <span v-else class="placeholder">Klasse wählen...</span>
                  <span class="arrow-down" :class="{ 'rotate': activeDropdown === 'klasse' }">▼</span>
                </div>

                <transition name="fade">
                  <div v-if="activeDropdown === 'klasse'" class="custom-options glass shadow-lg">
                    <div v-for="k in schuelerstundenplaene" :key="k.id"
                         class="custom-option select-row-layout"
                         :class="{ 'is-selected': lehrerPlanForm.klassen_id === k.id }"
                         @click="selectLehrerKlasse(k)">
                      <span class="option-name">{{ k.name }}</span>
                      <div class="check-icon-right" v-if="lehrerPlanForm.klassen_id === k.id">✔</div>
                    </div>
                  </div>
                </transition>
              </div>
              <input v-else type="text" v-model="lehrerPlanForm.klasse" placeholder="Name der neuen Klasse..."
                     class="glass-input-large">
            </div>

            <div class="input-group" style="display: flex; flex-direction: column; position: relative;">
              <div class="zeile lehrerstundenplan" style="display: flex; align-items: center; min-height: 32px;">
                <label>Raum:</label>
              </div>
              <div class="custom-select-wrapper" style="position: relative;">
                <div
                    class="custom-select-trigger glass-input-large"
                    :class="{
                      'rotate': activeDropdown === 'raum-edit',
                      'border-error': lehrerPlanForm.raum_ids.length > 0 && !isLehrerRaumVerfuegbar(lehrerPlanForm)
                  }"
                    @click.stop="toggleLehrerDropdown('raum-edit')"
                >
                  <span>{{ getLehrerRaumNamen(lehrerPlanForm) }}</span>
                  <span class="arrow-down" :class="{ 'rotate': activeDropdown === 'raum-edit' }">▼</span>
                </div>
              </div>

              <transition name="fade-up">
                <div v-if="activeDropdown === 'raum-edit'" class="custom-options glass shadow-lg">
                  <div v-for="r in raeume" :key="r.id"
                       class="custom-option select-row-layout"
                       :class="{ selected: lehrerPlanForm.raum_ids.includes(r.id) }"
                       @click.stop="toggleRaumSelection(r.id)"
                  >
                    {{ r.name }}
                    <span v-if="lehrerPlanForm.raum_ids.includes(r.id)" class="check-mark">✓</span>
                  </div>
                </div>
              </transition>
            </div>

            <div class="input-group"
                 :style="{display: 'flex', width: '100%', alignItems: 'center', justifyContent: 'center', gap: '20px'}">
              <label :style="{color:'white'}">Äußere Differenzierung?</label>
              <label class="switch">
                <input type="checkbox" v-model="lehrerPlanForm.is_differenzierung">
                <span class="slider round"></span>
              </label>
            </div>
          </div>

          <div class="input-group">
            <label>Fach oder Aktivität:</label>
            <div class="staff-grid-container stundentafel-grid">
              <div v-for="fach in faecher" :key="'f-'+fach.id"
                   class="staff-box glass-card"
                   :class="{ 'active-selection': selectedUniqueKey === 'f-' + fach.id }"
                   @click="selectLehrerFach(fach, 'f')">
                <div class="staff-avatar" :style="{ background: fach.farbe }">
                  {{ fach.name.substring(0, 2).toUpperCase() }}
                </div>
                <div class="staff-name">{{ fach.name }}</div>
              </div>
              <div v-for="a in aktivitaeten" :key="'a-'+a.id"
                   class="staff-box glass-card"
                   :class="{ 'active-selection': selectedUniqueKey === 'a-' + a.id }"
                   @click="selectLehrerFach(a, 'a')">
                <div class="staff-avatar" :style="{ background: a.farbe }">
                  {{ a.name.substring(0, 2).toUpperCase() }}
                </div>
                <div class="staff-name">{{ a.name }}</div>
              </div>
            </div>
          </div>

          <div class="input-group time-picker-section">
            <div class="input-row-triple">
              <div class="input-group">
                <div class="time-input-block">
                  <label>Beginn:</label>
                  <input type="time" v-model="lehrerPlanForm.start" class="glass-input-time"
                         @change="updateTimeFromUnits">
                </div>
              </div>
              <div v-if="selectedUniqueKey.startsWith('a')" class="input-group">
                <div class="time-input-block">
                  <label>Dauer:</label>
                  <input type="number"
                         :value="tempDauer"
                         placeholder="in Minuten"
                         @input="updateEndeByMinutes($event.target.value)"
                         class="glass-input-time">
                </div>
              </div>
              <div class="input-group">
                <div class="time-input-block">
                  <label>Ende:</label>
                  <div class="glass-input-time readonly-time"
                       :style="{height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center'}">
                    {{ lehrerPlanForm.ende || '--:--' }}
                  </div>
                </div>
              </div>
            </div>


            <transition name="slide-up">
              <div v-if="selectedUniqueKey.startsWith('f')" class="unit-slider-box glass-card">
                <div class="slider-header"
                     :style="{display: 'flex', alignItems: 'center', width: '100%', gap: '20px', justifyContent: 'center'}">
                  <small class="ue-badge">{{ stundenAuswahl }} UE</small>
                  <small class="min-info">{{ stundenAuswahl * 45 }} Minuten</small>
                </div>
                <input type="range" min="1" max="10" step="1"
                       v-model.number="stundenAuswahl"
                       @input="updateTimeFromUnits"
                       class="custom-slider">
                <div class="slider-labels">
                  <span>1</span><span>5</span><span>10</span>
                </div>
              </div>
            </transition>
          </div>
        </div>

        <div class="modal-footer">
          <button class="glass-btn-save"
                  @click="saveLehrerTermin">
            Eintrag speichern
          </button>
        </div>


      </div>
    </div>
  </transition>

  <!-- Eigenes Modal für den Diensteinsatzplan (Zweitkräfte): nur Raum, Aktivität, Beginn, Dauer, Ende -->
  <transition name="fade">
    <div v-if="showDienstPlanModal" class="modal-overlay" @click="showDienstPlanModal = false">
      <div class="modal-content glass-modal" @click.stop>

        <div class="modal-header">
          <h3><span class="icon">📅</span> Einsatz für {{ lehrerPlanForm.tag }}</h3>
          <button class="close-btn-circle" @click="resetLehrerForm">×</button>
        </div>

        <div class="modal-body">
          <div class="input-group" style="display: flex; flex-direction: column; position: relative;">
            <div class="zeile lehrerstundenplan" style="display: flex; align-items: center; min-height: 32px;">
              <label>Raum:</label>
            </div>
            <div class="custom-select-wrapper" style="position: relative;">
              <div
                  class="custom-select-trigger glass-input-large"
                  :class="{
                    'rotate': activeDropdown === 'dienst-raum-edit',
                    'border-error': lehrerPlanForm.raum_ids.length > 0 && !isLehrerRaumVerfuegbar(lehrerPlanForm)
                }"
                  @click.stop="toggleLehrerDropdown('dienst-raum-edit')"
              >
                <span>{{ getLehrerRaumNamen(lehrerPlanForm) }}</span>
                <span class="arrow-down" :class="{ 'rotate': activeDropdown === 'dienst-raum-edit' }">▼</span>
              </div>
            </div>

            <transition name="fade-up">
              <div v-if="activeDropdown === 'dienst-raum-edit'" class="custom-options glass shadow-lg">
                <div v-for="r in raeume" :key="r.id"
                     class="custom-option select-row-layout"
                     :class="{ selected: lehrerPlanForm.raum_ids.includes(r.id) }"
                     @click.stop="toggleRaumSelection(r.id)"
                >
                  {{ r.name }}
                  <span v-if="lehrerPlanForm.raum_ids.includes(r.id)" class="check-mark">✓</span>
                </div>
              </div>
            </transition>
          </div>

          <div class="input-group">
            <label>Aktivität:</label>
            <div v-if="lehrerPlanForm.einsatzort" class="einsatzort-hint">
              📍 Einsatzort: {{ lehrerPlanForm.einsatzort }}
            </div>
            <div class="staff-grid-container stundentafel-grid">
              <div v-for="a in aktivitaeten" :key="'a-'+a.id"
                   class="staff-box glass-card"
                   :class="{ 'active-selection': selectedUniqueKey === 'a-' + a.id }"
                   @click="selectLehrerFach(a, 'a')">
                <div class="staff-avatar" :style="{ background: a.farbe }">
                  {{ a.name.substring(0, 2).toUpperCase() }}
                </div>
                <div class="staff-name">{{ a.name }}</div>
              </div>
            </div>
          </div>

          <div class="input-group time-picker-section">
            <div class="input-row-triple">
              <div class="input-group">
                <div class="time-input-block">
                  <label>Beginn:</label>
                  <input type="time" v-model="lehrerPlanForm.start" class="glass-input-time"
                         @change="updateTimeFromUnits">
                </div>
              </div>
              <div class="input-group">
                <div class="time-input-block">
                  <label>Dauer:</label>
                  <input type="number"
                         :value="tempDauer"
                         placeholder="in Minuten"
                         @input="updateEndeByMinutes($event.target.value)"
                         class="glass-input-time">
                </div>
              </div>
              <div class="input-group">
                <div class="time-input-block">
                  <label>Ende:</label>
                  <div class="glass-input-time readonly-time"
                       :style="{height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center'}">
                    {{ lehrerPlanForm.ende || '--:--' }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="glass-btn-save" @click="saveLehrerTermin">
            Einsatz speichern
          </button>
        </div>

      </div>
    </div>
  </transition>

  <transition name="fade">
    <div v-if="showNewAktivitaetModal" class="modal-overlay" @click.self="showNewAktivitaetModal = false">
      <div class="modal-content glass-modal">
        <div class="modal-header">
          <h3><span class="icon">📚</span> Neue Aktivität</h3>
          <button class="close-btn-circle" @click="showNewAktivitaetModal=false">×</button>
        </div>

        <div class="modal-body">
          <div class="input-floating-group">
            <label>Name der Aktivität:</label>
            <input v-model="editingAktivitaet.name" placeholder="z.B. GU" class="glass-input-large"
                   @keyup.enter="saveActivity">
          </div>

          <div class="input-group">
            <label>Typ:</label>
            <div class="custom-select-wrapper">
              <div class="custom-select-trigger" @click.stop="toggleDropdown('type')">
                <span>{{ editingAktivitaet.typ || 'Typ wählen...' }}</span>
                <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('type') }">▼</span>
              </div>


              <transition name="fade">
                <div v-if="isDropdownOpen('type')" class="custom-options glass">
                  <div
                      v-for="t in ['AG','MSD/MSH','SVE','Mobile Reserve','Elternsprechstunde','Referendarsbetreuung','Förderunterricht','Systembetreuung','IB']"
                      :key="t"
                      class="custom-option"
                      @click="selectType(t)"
                  >
                    {{ t }}
                  </div>
                </div>
              </transition>
            </div>
          </div>

          <div class="input-floating-group">
            <label>Einsatzort:</label>
            <input v-model="editingAktivitaet.einsatzort" placeholder="z.B. HPT" class="glass-input-large">
          </div>
        </div>

        <div class="input-group full-width">
          <label>Termine (Besetzung, Ort & Zeit):</label>

          <div v-for="(termin, index) in editingAktivitaet.termine" :key="index" class="termin-card glass">
            <div class="termin-main-row">
              <div class="custom-select-wrapper tag-select">
                <div class="custom-select-trigger" @click.stop="toggleDropdown('tag-' + index)">
                  <span>{{ termin.tag || 'Tag wählen...' }}</span>
                  <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('tag-' + index) }">▼</span>
                </div>
                <transition name="fade">
                  <div v-if="isDropdownOpen('tag-' + index)" class="custom-options glass">
                    <div v-for="d in days" :key="d" class="custom-option" @click="selectTagForTermin(index, d)">
                      {{ d }}
                    </div>
                  </div>
                </transition>
              </div>

              <div class="time-col-wrapper">
                <input v-model="termin.uhrzeit" type="time" class="glass-input time-input">
                <input v-model="termin.endzeit" type="time" class="glass-input time-input">
              </div>

              <button class="remove-btn" @click="editingAktivitaet.termine.splice(index, 1)">×</button>
            </div>

            <div class="termin-row mt-10">
              <div class="custom-select-wrapper" style="flex: 1;">
                <div
                    class="custom-select-trigger"
                    :class="{ 'rotate': isDropdownOpen('raum-' + index),
                          'border-error': termin.raum_id && !isRaumVerfuegbar(termin.raum_id, termin.tag, termin.uhrzeit, termin.endzeit)
                      }"
                    @click.stop="toggleDropdown('raum-' + index)"
                >
                  <span>{{ getRaumNamen(termin) }}</span>
                  <span class="arrow-down" :class="{ 'rotate': isDropdownOpen('raum-' + index) }">▼</span>
                </div>

                <div
                    v-if="termin.raum_id && !isRaumVerfuegbar(termin.raum_id, termin.tag, termin.uhrzeit, termin.endzeit)"
                    class="error-message-inline"
                >
                  ⚠️ Raum zu dieser Zeit belegt/geschlossen
                </div>
                <transition name="fade">
                  <div v-if="isDropdownOpen('raum-' + index)" class="custom-options glass">
                    <div v-for="r in raeume" :key="r.id"
                         class="custom-option"
                         :class="{ selected: termin.raeume.includes(r.id) }"
                         @click.stop="toggleSelection(termin.raeume, r.id)">
                      {{ r.name }}
                      <span v-if="termin.raeume.some(id => id == r.id)">✓</span>
                    </div>
                    <div class="custom-option add-option" @click.stop="openQuickAdd('raum', index)">+ Raum
                      hinzufügen
                    </div>
                  </div>
                </transition>
              </div>
            </div>
          </div>
        </div>

        <button class="glass-btn btn-add-inline" @click="addTermin">+ Termin hinzufügen</button>

        <div class="modal-footer">
          <button class="glass-btn-save" :disabled="!editingAktivitaet.name" @click="saveActivity">
            Aktivität speichern
          </button>
        </div>
      </div>
    </div>
  </transition>

  <transition name="fade">
    <div v-if="dialog.show" class="elli-dialog-overlay"
         @click.self="_dialogClose(dialog.mode === 'confirm' ? false : true)">
      <div class="elli-dialog" role="dialog" aria-modal="true">
        <div v-if="dialog.title" class="elli-dialog-title">{{ dialog.title }}</div>
        <div class="elli-dialog-message">{{ dialog.message }}</div>
        <div class="elli-dialog-actions">
          <button v-if="dialog.mode === 'confirm'" class="elli-dialog-btn secondary"
                  @click="_dialogClose(false)">{{ dialog.cancelText }}</button>
          <button ref="dialogOk" class="elli-dialog-btn primary"
                  @click="_dialogClose(true)"
                  @keydown.esc="_dialogClose(dialog.mode === 'confirm' ? false : true)">{{ dialog.okText }}</button>
        </div>
      </div>
    </div>
  </transition>
</template>

<style>
html, body {
  margin: 0;
  padding: 0;
  width: 100%;
  min-height: 100vh;
  background-color: #1c1c1c; /* Verhindert weißes Blitzen beim Laden */
  overflow-x: hidden;
}

:root {
  --zero: #1e6c38;
  --primary: #439f62; /* Dein Akzent-Grün */
  --secondary: rgba(35, 164, 74, 0.2);
  --negative: #a44444; /* Dein Button-Rot */
  --background: #1c1c1c;
  --rainbow: linear-gradient(45deg, #d8ffca, #a4ff81, #5ce824, #36cf00);
  --glass-bg: rgba(255, 255, 255, 0.05);
  --glass-border: rgba(255, 255, 255, 0.1);
  --accent-green: #2ed573;
  --accent-red: #ff4757;
  --accent-muted: #5a5e3c;
  --text-dim: #a0a0a0;
}

/* Diese Regeln gelten für die gesamte Seite */
::-webkit-scrollbar {
  width: 8px;
}

::-webkit-scrollbar-thumb {
  background: #444;
  border-radius: 10px;
}

* {
  scrollbar-width: thin;
  scrollbar-color: #444 transparent;
}
</style>

<style scoped>
input::-webkit-outer-spin-button,
input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

/* Versteckt die Pfeile in Firefox */
input[type=number] {
  -moz-appearance: textfield;
}

input:focus,
textarea:focus,
select:focus {
  outline: none; /* Entfernt den standardmäßigen blauen Browser-Rahmen */
  border-color: #2ecc71 !important; /* Ein schönes Smaragdgrün */
  box-shadow: 0 0 8px rgba(46, 204, 113, 0.4); /* Ein sanfter grüner Glüheffekt */
  transition: border-color 0.3s, box-shadow 0.3s; /* Macht den Übergang weich */
}


.snackbar {
  position: fixed;
  bottom: 30px;
  left: 50%;
  transform: translateX(-50%);
  padding: 12px 24px;
  border-radius: 12px;
  color: white;
  font-weight: 600;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
  z-index: 999999 !important;
  min-width: 250px;
  text-align: center;
}

.snackbar.success {
  background-color: var(--primary)
}

.snackbar.error {
  background-color: var(--negative)
}

/* Animation */
.slide-up-enter-active, .slide-up-leave-active {
  transition: all 0.3s ease;
}

.slide-up-enter-from, .slide-up-leave-to {
  transform: translate(-50%, 100px);
  opacity: 0;
}

/* Grund-Layout auf voller Breite */
.app-container {
  min-height: 100vh;
  width: 100%;
  background-color: #1c1c1c; /* Dunkelgrauer Hintergrund */
  color: white;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  overflow-x: hidden;
}

/* Header */
.main-header {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  /* Nutze eine feste Höhe statt nur Padding, um vertikale Sprünge zu vermeiden */
  height: 120px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 60px; /* Padding nur links/rechts, vertikal regelt 'height' und 'align-items' */
  z-index: 110;
  pointer-events: none;
  isolation: isolate;
  transform: translateZ(0); /* Erzwingt Hardware-Beschleunigung für Schärfe */
}

.burger-menu {
  pointer-events: auto; /* ...außer beim Burger-Menü selbst! */
  display: flex;
  align-items: center;
  gap: 20px;
  cursor: pointer;
}

/* CSS Burger-Lines Animation */
.burger-lines {
  width: 30px;
  height: 20px;
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.burger-lines span {
  display: block;
  width: 100%;
  height: 3px;
  background-color: white;
  transition: all 0.3s ease-in-out;
  border-radius: 2px;
}

/* Transformation zum X */
.is-active .burger-lines span:nth-child(1) {
  transform: translateY(8.5px) rotate(45deg);
}

.is-active .burger-lines span:nth-child(2) {
  opacity: 0; /* Mittlere Linie verschwindet */
}

.is-active .burger-lines span:nth-child(3) {
  transform: translateY(-8.5px) rotate(-45deg);
}

.header-right {
  pointer-events: auto;
}

.back-circle {
  /* Größe und Form */
  width: 50px !important;
  height: 50px !important;
  border-radius: 50% !important;

  /* Reset von Button-Standards */
  appearance: none;
  -webkit-appearance: none;
  padding: 0 !important;
  margin: 0 !important;
  border: 1px solid rgba(255, 255, 255, 0.2) !important;
  background: rgba(255, 255, 255, 0.05) !important;

  /* Zentrierung erzwingen */
  display: flex !important;
  justify-content: center !important;
  align-items: center !important;

  /* Interaktion */
  cursor: pointer !important;
  pointer-events: auto !important;
  z-index: 999;
}

.arrow {
  /* Sicherstellen, dass der Pfeil keine Eigenhöhen hat */
  display: inline-block !important;
  line-height: 1 !important;
  text-align: center !important;
  color: white !important;
  font-size: 24px !important;

  /* Manuelle Korrektur, falls das Symbol selbst "untenlastig" ist */
  margin-top: -4px !important;
  /* Falls er zu weit links klebt, hier leicht nach rechts schieben: */
  margin-left: -2px !important;
}

.back-circle:hover {
  background: rgba(255, 255, 255, 0.1);
  border-color: rgba(255, 255, 255, 0.4);
}

/* Sidebar / Settings im Glas-Look */
.settings-sidebar {
  position: fixed;
  top: 0;
  left: 0;
  width: 350px; /* Etwas breiter für die Jahreszahl */
  height: 100vh;
  padding: 120px 40px 40px 60px; /* Oben Platz lassen für den Header */
  z-index: 100;
  background: rgba(30, 30, 30, 0.9);
  backdrop-filter: blur(20px);
}

/* Sidebar: Schiebt sich von links rein */
.slide-enter-active, .slide-leave-active {
  transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-enter-from, .slide-leave-to {
  transform: translateX(-100%);
}

.input-group {
  margin-bottom: 20px;
}

.input-group label {
  display: block;
  font-size: 0.85rem;
  color: #999;
  margin-bottom: 8px;
}

input, textarea, .custom-select-trigger {
  width: 100%;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 10px;
  color: white;
  padding: 12px 15px;
  box-sizing: border-box;
  margin-bottom: 10px;
  height: 50px; /* Festgelegte Höhe für perfekte Symmetrie */
  display: flex;
  align-items: center;
  font-size: 1rem;
  line-height: 1;
}

.btn-disabled {
  opacity: 0.3;
  filter: grayscale(1);
  cursor: not-allowed !important;
  transform: none !important;
  box-shadow: none !important;
}

textarea {
  height: auto;
  min-height: 80px;
}

.schuljahr-manager {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.year-list {
  display: grid;
  /* Erstellt zwei Spalten, wenn Platz da ist, sonst eine */
  grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
  gap: 10px;
  margin-bottom: 10px;
  width: 100%;
}

.year-list:has(.year-item:nth-child(3)) {
  grid-template-columns: repeat(2, 1fr);
}

.year-list {
  grid-template-columns: 1fr;
}

.year-item {
  padding: 10px;
  text-align: center;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.1);
  cursor: pointer;
  transition: all 0.2s ease;
  /* Verhindert, dass der Text bei kleinen Breiten umbricht */
  white-space: nowrap;
}

.year-item:hover {
  background: rgba(255, 255, 255, 0.1);
  color: white;
}

.year-item.active {
  background: rgba(28, 138, 60, 0.3); /* Dein Grün-Ton dezent */
  border: 1.5px solid #1c8a3c;
  color: white;
  font-weight: bold;
}

.add-year-btn {
  width: 35px;
  height: 35px;
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  background: rgba(255, 255, 255, 0.1);
  color: white;
  cursor: pointer;
  font-size: 1.2rem;
  margin-top: 5px;
}

.add-year-btn:hover {
  background: #1c8a3c;
}

.content-full {
  display: flex;
  flex-direction: column;
  height: 100vh; /* Volle Bildschirmhöhe */
  box-sizing: border-box;
}

/* Hero-Sektion */
.hero-section {
  text-align: center;
  min-height: 50vh;
  display: flex;
  flex-direction: column;
  justify-content: center; /* Zentriert den Text vertikal in den 50% */
  align-items: center;
  margin-top: 0; /* Header-Abstand wird durch Flex/Padding geregelt */
  margin-bottom: 40px;
}

.sub-title {
  font-size: 2.2rem;
  color: #777;
  font-weight: 300;
  margin-top: 20px;
}

.grid-layout {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 15px 45px; /* 15px vertikal (oben/unten), 45px horizontal (links/rechts) */
  width: 80%;
  margin: 0 auto;
  padding: 0 60px;
  box-sizing: border-box;
}

/* Der obere Grid-Block schiebt alles nach unten */
.top-grid {
  margin-top: auto;
}

/* Der untere Grid-Block bekommt ein festes Padding nach unten, damit er nicht am Rand klebt */
.bottom-grid {
  margin-bottom: 40px; /* Fixer Abstand zum unteren Bildschirmrand */
}

.separator-full {
  width: 80%;
  height: 3px;
  background: linear-gradient(90deg, transparent, #333, transparent);
  margin: 20px auto; /* Sehr schmaler Abstand zum Trenner */
}

/* Button-Styling */
.glass-btn {
  height: clamp(60px, 8vh, 90px);
  border-radius: 20px; /* Etwas kantiger wirkt oft moderner als vollrund */
  font-size: 1.1rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 15px;
  background: rgba(255, 255, 255, 0.03); /* Fast transparent */
  border: 1px solid rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(10px);
  color: #ffffff;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}

/* Akzent-Buttons (Die grünen Buttons) */
.btn-accent {
  background: var(--zero);
  /* Ein innerer Schatten lässt den Button wie ein echtes Objekt wirken */
  border: 1px solid rgba(255, 255, 255, 0.1);
}

.bottom {
  background: var(--secondary);
}

/* Der "Leuchteffekt" beim Drüberfahren */
.btn-accent:hover {
  transform: translateY(-4px) scale(1.02);
  background: linear-gradient(135deg, #2bc057 0%, #1c8a3c 100%);
  box-shadow: inset 0 2px 4px rgba(255, 255, 255, 0.4),
  0 15px 30px rgba(28, 138, 60, 0.4);
}

/* Aktiver Klick-Zustand */
.glass-btn:active {
  transform: translateY(0);
}


/* Regenbogen-Effekt */
.btn-rainbow {
  background: linear-gradient(#1c1c1c, #1c1c1c) padding-box,
  linear-gradient(45deg, #d8ffca, #a4ff81, #5ce824, #36cf00) border-box;
  border: 1.5px solid transparent;
  opacity: 0.9;
}

.btn-rainbow:hover {
  opacity: 1;
  transform: translateY(-3px);
  box-shadow: 0 8px 25px rgba(255, 255, 255, 0.1);
}

/* Der Text-Effekt */
.btn-rainbow span {
  background: linear-gradient(45deg, #d8ffca, #a4ff81, #5ce824, #36cf00);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}

.btn-rainbow span {
  transition: all 0.3s ease;
}

/* Hover-Effekte */
.glass-btn:hover {
  transform: scale(1.03);
  box-shadow: 0 0 25px rgba(74, 124, 44, 0.3);
}

.category-grid {
  grid-template-columns: repeat(3, 1fr); /* Festgelegte 3 Spalten */
  margin-top: 20px;
  padding-bottom: 60px;
}

.hero-section.small {
  min-height: 20vh;
  margin-top: 0;
  /* Füge oben einen Abstand ein, der exakt der Header-Höhe entspricht */
  padding-top: 120px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
}

.btn-add {
  background: rgba(255, 255, 255, 0.05);
  border: 2px dashed rgba(255, 255, 255, 0.2);
}

.white-text {
  color: white;
  font-weight: bold;
  height: 47px;
}

.btn-add:hover {
  background: rgba(255, 255, 255, 0.1);
  border-color: #1c8a3c;
}

.custom-icon-svg {
  height: 28px;
  width: 28px;
  vertical-align: middle;
  filter: brightness(0) invert(1);
}

.title-with-icon {
  display: flex; /* Aktiviert Flexbox */
  flex-direction: row; /* Richtet Elemente horizontal aus */
  align-items: center; /* Zentriert Icon und Text vertikal zueinander */
  justify-content: center; /* Zentriert die gesamte Gruppe im Bildschirm */
  gap: 30px; /* Der Abstand zwischen Icon und Text */
  width: 100%;
}

/* Styling für das große Icon im Header */
.title-icon-svg {
  height: 90px; /* Entspricht etwa der Größe in deinem Screenshot */
  width: auto;
  filter: brightness(0) invert(1); /* Macht das importierte SVG weiß */
  flex-shrink: 0; /* Verhindert, dass das Icon gequetscht wird */
}

.main-title {
  margin: 0; /* Entfernt Standard-Abstände vom h1 */
  font-size: clamp(3rem, 6vw, 5rem); /* Dynamische Größe */
  line-height: 1;
}

/* Optional: Abstand für die kleine Hero-Sektion anpassen */
.hero-section.small {
  min-height: 25vh;
  padding-top: 120px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.editor-container {
  width: 80%;
  margin: 40px auto;
  padding: 40px;
  border-radius: 30px;
}

.editor-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 30px;
}

.editor-grid-aktivitaet {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 30px;
}

.custom-select-wrapper {
  position: relative; /* Wichtig für die absolute Positionierung der Liste */
  width: 100%;
}

.custom-options {
  display: flex;
  flex-direction: column; /* Stapelt Einträge vertikal */
  position: absolute;
  top: calc(100% + 5px);
  left: 0;
  right: 0;
  background: rgba(30, 30, 30, 0.98);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  z-index: 9999;
  max-height: 250px;
  overflow-y: auto;
}

/* Der Hinzufügen-Button am Ende der Liste */
.btn-add-inline {
  position: sticky; /* Optional: Bleibt beim Scrollen immer unten sichtbar */
  bottom: 0;
  width: 100%;
  padding: 12px 15px;
  background: rgba(35, 164, 74, 0.1);
  border-top: 1px solid rgba(255, 255, 255, 0.1);
  color: #23a44a;
  text-align: left;
  cursor: pointer;
  z-index: 10;
}

.custom-option {
  padding: 12px 15px;
  cursor: pointer;
  color: white;
  transition: background 0.2s;
}

.custom-option:hover {
  background: rgba(35, 164, 74, 0.4); /* Dein Grün-Ton bei Hover */
}

.custom-select-trigger {
  justify-content: space-between;
  cursor: pointer;
}

.dropdown-header {
  padding: 10px 15px 5px 15px;
  font-size: 0.75rem;
  color: #1c8a3c; /* Dein Grünton */
  text-transform: uppercase;
  font-weight: bold;
  letter-spacing: 1px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.05);
  margin-bottom: 5px;
}

.arrow-down {
  font-size: 0.8rem;
  transition: transform 0.3s;
}

.arrow-down.rotate {
  transform: rotate(180deg);
}

.row-flex {
  display: flex;
  gap: 25px;
  align-items: flex-start;
}

.row-flex .input-group {
  flex: 1;
}

.btn-add-inline {
  color: #23a44a !important; /* Dein Akzent-Grün */
  font-weight: 600;
  border-top: 1px solid rgba(255, 255, 255, 0.05);
  background: rgba(35, 164, 74, 0.05);
  margin-top: 5px;
}

.btn-add-inline:hover {
  background: rgba(35, 164, 74, 0.15) !important;
  color: #2bc057 !important;
}

/* Scrollbar-Styling für das Dropdown, damit es bei vielen Einträgen edel bleibt */
.custom-options::-webkit-scrollbar {
  width: 6px;
}

.custom-options::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 10px;
}

.termin-card {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 15px;
  padding: 20px;
  margin-bottom: 20px;
  display: flex;
  flex-direction: column;
  gap: 15px;
}

.termin-main-row {
  display: flex;
  flex-direction: row;
  align-items: center; /* Zentriert alles vertikal */
  gap: 15px;
  width: 100%;
}

.tag-select {
  flex: 1;
}

.time-input {
  flex: 1; /* Die Uhrzeit ist schmaler */
}

.time-col-wrapper {
  flex: 1;
  display: flex;
  gap: 15px;
  align-items: center;
}

.termin-row {
  display: flex;
  gap: 15px;
  align-items: center; /* Zentriert den Button vertikal zur Input-Höhe */
  flex-wrap: nowrap; /* Verhindert das Verrutschen in die nächste Zeile */
  width: 100%;
}

.termin-row.mt-10 {
  display: flex;
  gap: 15px;
  width: calc(100% - 65px); /* Abzug für den Löschen-Button oben, damit es bündig ist */
}

.glass-input[type="time"] {
  min-width: 120px;
}

.add-option {
  color: #23a44a !important; /* Dein Akzent-Grün */
  border-top: 1px solid rgba(255, 255, 255, 0.1);
  margin-top: 5px;
  text-align: center;
}

.add-option:hover {
  background: rgba(35, 164, 74, 0.2) !important;
}

.separator-inner {
  height: 1px;
  background: rgba(255, 255, 255, 0.1);
  margin: 10px 0;
}

.save-btn {
  margin-top: 10px;
  background-color: var(--primary); /* Grün */
  color: white;
  border: none;
  padding: 8px 12px;
  border-radius: 4px;
  cursor: pointer;
  width: 100%;
  font-weight: bold;
}

.save-btn:hover {
  background-color: #45a049;
}

.remove-btn {
  width: 50px; /* Verhindert das Schrumpfen oder Wachsen des Buttons */
  height: 50px;
  background: rgba(164, 68, 68, 0.2);
  border: 1px solid #a44444;
  color: #ff6b6b;
  border-radius: 10px;
  cursor: pointer;
  font-size: 1.5rem;
  margin-bottom: 10px;
  justify-content: center;
  align-items: center;
}

.remove-btn:hover {
  background: #a44444;
  color: white;
}

/* Damit das Editor-Grid bei den Terminen nicht stört */
.full-width {
  grid-column: 1 / -1;
}

/* Trennlinie und Abstand am Ende der Karte */
.form-footer {
  margin-top: 20px;
  padding-top: 10px;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
  display: flex;
  justify-content: center;
}

/* Haupt-Speicherbutton über die volle Breite */
.btn-save-main {
  width: 100%;
  padding: 15px;
  font-size: 1.1rem;
  font-weight: 600;
  /* Ein dezenter grüner Verlauf passend zum Glas-Look */
  background: linear-gradient(135deg, rgba(35, 164, 74, 0.4), rgba(35, 164, 74, 0.2));
  border: 1px solid rgba(114, 255, 178, 0.3);
  color: white;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.btn-save-main:hover:not(.btn-disabled) {
  background: linear-gradient(135deg, rgba(35, 164, 74, 0.6), rgba(35, 164, 74, 0.3));
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(35, 164, 74, 0.3);
}

.btn-disabled {
  opacity: 0.4;
  cursor: not-allowed;
  filter: grayscale(1);
}

/* Der Button selbst */
.item-button {
  position: relative; /* Wichtig für die Positionierung des X */
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 80px;
}

/* Der Inhalts-Container (Ersatz für das div) */
.button-content-wrapper {
  display: block;
  width: 100%;
}

.item-type {
  display: block;
  font-size: 0.7em;
  opacity: 0.8;
  margin-top: 4px;
}

/* Das Lösch-X */
.delete-overlay-btn {
  position: absolute;
  top: 4px;
  right: 4px;
  width: 24px;
  height: 24px;
  background: rgba(255, 68, 68, 0.2);
  color: white;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  line-height: 1;
  transition: all 0.2s ease;
  opacity: 0; /* Standardmäßig unsichtbar */
  z-index: 10;
}

/* X anzeigen, wenn man mit der Maus über die Kachel fährt */
.item-button:hover .delete-overlay-btn {
  opacity: 1;
}

/* Hover-Effekt für das X selbst */
.delete-overlay-btn:hover {
  background: #ff4444;
  transform: scale(1.1);
  box-shadow: 0 0 10px rgba(255, 68, 68, 0.5);
}

.form-footer {
  margin-top: 2rem;
  width: 100%;
  display: flex;
  justify-content: center;
}

.btn-disabled {
  opacity: 0.5;
  cursor: not-allowed;
  filter: grayscale(1);
}

.custom-number-input {
  display: flex;
  align-items: center;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  overflow: hidden;
}

.custom-number-input input {
  border: none !important;
  text-align: center;
  background: transparent !important;
  width: 50px;
  flex-grow: 1;
  margin: 0 !important;
}

.custom-number-input button {
  background: rgba(255, 255, 255, 0.1);
  border: none;
  color: white;
  padding: 10px 15px;
  cursor: pointer;
  font-size: 1.2rem;
  transition: background 0.2s;
}

.custom-number-input button:hover {
  background: rgba(35, 164, 74, 0.6);
}

/* Container für die Drei-Spalten-Zeile */
.input-row-triple {
  display: flex;
  gap: 10px; /* Abstand zwischen den Feldern */
  margin-bottom: 15px;
}

.input-row-triple .input-group {
  flex: 1; /* Alle drei Boxen sind gleich breit */
  margin-bottom: 0; /* Margin unten entfernen, da Reihe */
}

/* Optik für das UPZ-Ergebnisfeld */
.readonly-input {
  background: rgba(255, 255, 255, 0.05) !important;
  border: 1px dashed rgba(255, 255, 255, 0.2) !important;
  color: rgba(35, 164, 74, 0.6) !important;
  font-weight: bold;
  text-align: center;
  cursor: default;
}

/* Label-Anpassung für schmale Spalten */
.input-row-triple label {
  font-size: 0.8rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.input-row-dual {
  display: flex;
  gap: 15px;
  margin-bottom: 15px;
}

.input-row-dual .input-group {
  flex: 1;
  margin-bottom: 0;
}

.fifty-percent {
  flex: 1;
}

.color-picker-wrapper {
  height: 50px;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.2);
}

.color-picker-wrapper input[type="color"] {
  width: 150%; /* Versteckt die Standard-Ränder des Browsers */
  height: 150%;
  cursor: pointer;
  border: none;
  background: none;
  transform: translate(-10%, -10%);
}

/* WICHTIG: Verhindere, dass das Dropdown das Layout verschiebt */
.custom-options {
  position: absolute; /* Muss absolut sein! */
  z-index: 1000;
  /* ... restliche Styles */
}

.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(8px); /* Stärkerer Blur für Fokus */
  z-index: 2000;
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 20px;
}

.glass-modal {
  background: rgba(255, 255, 255, 0.15);
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 24px;
  width: 90vw;
  max-width: 850px; /* Breiter gemacht für 5 Spalten */
  max-height: 90vh;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
  padding: 24px;
  color: white;
  display: flex;
  flex-direction: column;
  /* Ermöglicht das Mitwachsen der Höhe */
  height: auto;
  overflow-y: auto;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.close-btn-circle {
  background: rgba(255, 255, 255, 0.1);
  border: none;
  color: white;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  cursor: pointer;
  font-size: 20px;
}

.input-floating-group {
  margin-bottom: 15px;
}

.input-floating-group label {
  display: block;
  font-size: 12px;
  margin-bottom: 5px;
  color: rgba(255, 255, 255, 0.7);
  margin-left: 5px;
}

.glass-input-large {
  width: 100%;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.1);
  padding: 12px 15px;
  border-radius: 12px;
  color: white;
  font-size: 16px;
}

.availability-toggle-box {
  background: rgba(255, 255, 255, 0.05);
  padding: 15px;
  border-radius: 15px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 20px 0;
}

.toggle-text {
  display: flex;
  flex-direction: column;
}

.toggle-text span {
  font-size: 11px;
  color: rgba(255, 255, 255, 0.6);
}

/* Switch Styling (Slider) */
.switch {
  position: relative;
  display: inline-block;
  width: 44px;
  height: 24px;
}

.switch input {
  opacity: 0;
  width: 0;
  height: 0;
}

.slider {
  position: absolute;
  cursor: pointer;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(255, 255, 255, 0.2);
  transition: .4s;
  border-radius: 34px;
}

.slider:before {
  position: absolute;
  content: "";
  height: 18px;
  width: 18px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: .4s;
  border-radius: 50%;
}

input:checked + .slider {
  background-color: #4CAF50;
}

input:checked + .slider:before {
  transform: translateX(20px);
}

.availability-row-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  margin-bottom: 10px;
  border-radius: 12px;
  position: relative;
}

.time-inputs {
  display: flex;
  align-items: center;
  gap: 8px;
  justify-content: center;
}

.time-inputs input {
  background: transparent;
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: white;
  padding: 5px;
  border-radius: 5px;
}

.btn-ghost-add {
  width: 100%;
  background: transparent;
  border: 1px dashed rgba(255, 255, 255, 0.3);
  color: white;
  padding: 10px;
  border-radius: 10px;
  cursor: pointer;
  margin-top: 10px;
}

.modal-footer {
  display: flex;
  gap: 10px;
  margin-top: 25px;
}

.glass-btn-save {
  flex: 2;
  background: rgba(76, 175, 80, 0.6);
  border: none;
  padding: 12px;
  border-radius: 12px;
  color: white;
  font-weight: bold;
}

.glass-btn-cancel {
  flex: 1;
  background: rgba(255, 255, 255, 0.1);
  border: none;
  padding: 12px;
  border-radius: 12px;
  color: white;
}

.hint-text-small {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.4);
  margin-top: 10px;
  text-align: center;
  font-style: italic;
}

.raum-grid-selection {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 10px;
  padding: 15px;
  max-height: 250px;
  overflow-y: auto;
  margin-top: 10px;
  border-radius: 12px;
}

.raum-checkbox-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
  border: 1px solid transparent;
}

.raum-checkbox-item:hover {
  background: rgba(255, 255, 255, 0.1);
}

.raum-checkbox-item.active {
  background-color: var(--secondary) !important;
  border-color: var(--primary) !important;
}

.checkbox-box {
  width: 20px;
  height: 20px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  line-height: 1;
}

.active .checkbox-box {
  border-color: var(--primary);
}

.input-subtext {
  font-size: 0.85rem;
  opacity: 0.7;
  margin-bottom: 5px;
}

.new-raum-name-label {
  background: var(--rainbow);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}

/* Modal Hintergrund (abdunkeln & blurren) */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(8px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2000;
}

/* Das Glas-Fenster */
.modal-content.glass {
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 20px;
  padding: 2rem;
  max-width: 800px;
  width: 90%;
  max-height: 80vh;
  overflow-y: auto;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.37);
  color: white;
}

/* Styling für die extrahierten Datenzeilen */
.import-item {
  background: rgba(255, 255, 255, 0.05);
  margin-bottom: 1rem;
  padding: 1rem;
  border-radius: 12px;
  border-left: 4px solid #00d2ff;
}

.data-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 15px;
  margin-top: 10px;
}

.glass-input.small {
  padding: 8px;
  font-size: 0.9rem;
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: white;
  border-radius: 5px;
}

.modal-buttons {
  display: flex;
  justify-content: flex-end;
  gap: 1rem;
  margin-top: 2rem;
}

.invisible {
  opacity: 0;
}

.upz-value {
  font-weight: bolder;
  color: var(--primary);
}

/* Container Layout */
.timetable-editor-container {
  display: flex;
  gap: 20px;
  height: calc(100vh - 140px);
  padding: 0 40px 20px 40px;
  animation: fadeIn 0.5s ease-out;
}

.timetable-main-content {
  height: 100%;
  overflow: hidden;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 15px;
  min-width: 0; /* Verhindert Flex-Overflow */
}

.room-timetable-main-content {
  display: flex;
  flex: 1;
  flex-direction: column;
  height: 53vw;
  width: 100%;
}

/* Grid & Scroll Area */
.timetable-scroll-area {
  display: flex; /* NEU: Macht die Area selbst zum Flex-Container */
  flex-direction: column;
  flex: 1;
  overflow-y: auto;
  height: 75vh;
  border-radius: 20px;
  border: 1px solid rgba(255, 255, 255, 0.05);
  margin-bottom: 50px;
}

.grid-layout-wrapper {
  min-width: 800px;
  display: flex;
  flex-direction: column;
  flex: 1 0 auto;
  min-height: 100%;
}

.grid-body {
  display: flex;
  flex-direction: column;
  flex: 1; /* Das hier füllt den leeren Raum unter den Zeilen aus */
}

.grid-header-row {
  display: grid;
  grid-template-columns: 100px repeat(5, 1fr);
  background: rgba(255, 255, 255, 0.03);
  position: sticky;
  top: 0;
  z-index: 10;
  flex-shrink: 0;
}

.day-header, .time-header {
  padding: 15px;
  text-align: center;
  font-weight: 600;
  color: #aaa;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-size: 0.75rem;
}

.grid-row {
  display: grid;
  flex: 1;
  min-height: 60px;
  grid-template-columns: 100px repeat(5, 1fr);
  border-bottom: 1px solid rgba(255, 255, 255, 0.03);
}

.time-label {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.02);
  border-right: 1px solid rgba(255, 255, 255, 0.05);
}

.time-label.small {
  font-size: 0.7rem;
}

.grid-cell {
  min-height: 85px;
  padding: 8px;
  transition: all 0.2s ease;
  border-right: 1px solid rgba(255, 255, 255, 0.02);
  flex-direction: row;
  overflow: hidden;
}

.grid-cell.drag-over {
  background: var(--primary);
  box-shadow: inset 0 0 15px rgba(255, 255, 255, 0.05);
}

.subject-chip {
  height: 100%;
  border-radius: 8px;
  padding: 4px 8px;
  display: flex;
  justify-content: center;
  align-items: center;
  position: relative;
  overflow: hidden;
}

.chip-info {
  display: flex;
  flex-direction: column;
  min-width: 0; /* DER KEY: Erlaubt dem Flex-Item, kleiner als sein Inhalt zu werden */
  flex: 1; /* Erlaubt dem Chip zu schrumpfen und zu wachsen */
  text-align: center;
  overflow: hidden;
}

.chip-info strong {
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  width: 100%; /* Bezieht sich auf die min-width: 0 von .chip-info */
  font-size: 0.85rem;
}

.chip-info small {
  font-size: 0.7rem;
  opacity: 0.7;
}

.subject-chip:hover .remove-chip {
  opacity: 1;
}

.subject-scroll-container {
  line-height: 1.5;
  max-height: 6lh;
  flex: 1;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  overflow-y: auto;
}

.draggable-subject {
  padding: 9px 18px;
  border-radius: 12px;
  cursor: grab;
  font-size: 1.25rem;
  align-items: center;
  gap: 10px;
  transition: transform 0.2s;
  max-width: 400px;
  overflow: hidden;
  white-space: nowrap; /* Verhindert den Zeilenumbruch */
  text-overflow: ellipsis; /* Fügt die "..." am Ende hinzu */
  display: block; /* Falls Flexbox die Ellipsis "verschluckt" */
  text-align: center; /* Optional: Damit der Text mittig bleibt */
}

.draggable-subject:active {
  cursor: grabbing;
  transform: scale(0.95);
}

.draggable-subject-activity {
  padding: 9px 18px;
  border-radius: 12px;
  border-style: solid;
  border-width: 2px;
  border-color: var(--primary);
  color: var(--primary);
  cursor: grab;
  font-size: 1.25rem;
  align-items: center;
  gap: 10px;
  transition: transform 0.2s;
  max-width: 400px;
  overflow: hidden;
  white-space: nowrap; /* Verhindert den Zeilenumbruch */
  text-overflow: ellipsis; /* Fügt die "..." am Ende hinzu */
  display: block; /* Falls Flexbox die Ellipsis "verschluckt" */
  text-align: center; /* Optional: Damit der Text mittig bleibt */
}

/* Animation */
@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.slider-group {
  margin: 10px 0;
  padding: 15px;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 12px;
}

.custom-slider {
  width: 100%;
  height: 6px;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 5px;
  outline: none;
  -webkit-appearance: none;
  margin: 5px 0;
}

.custom-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 20px;
  height: 20px;
  background: var(--primary, #3498db);
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 0 10px rgba(52, 152, 219, 0.5);
  transition: transform 0.2s;
}

.custom-slider::-webkit-slider-thumb:hover {
  transform: scale(1.2);
}

.slider-labels {
  display: flex;
  justify-content: space-between;
  color: rgba(255, 255, 255, 0.4);
  font-size: 0.6rem;
  padding: 0 5px;
  height: 5px;
}

.time-display-bubble {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 15px;
  margin-top: 15px;
  padding: 5px;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 8px;
}

.units {
  font-weight: bold;
  color: var(--primary);
}

.real-time {
  font-weight: bold;
  color: #fff;
}

.arrow {
  color: rgba(255, 255, 255, 0.3);
}

.main-header-plaene {
  top: 0;
  left: 0;
  margin-left: 120px;
  margin-right: 120px;
  /* Nutze eine feste Höhe statt nur Padding, um vertikale Sprünge zu vermeiden */
  height: 120px;
  display: flex;
  align-items: center;
  gap: 20px;
}

.glass-input-class {
  width: 100%;
  background: #1c1c1c;
  border: 1px solid rgba(255, 255, 255, 0.1);
  padding: 12px 15px;
  border-radius: 12px;
  color: white;
  font-size: 28px;
  font-weight: bold;
  text-align: center;
}

.raum-plaene-head {
  width: 100%;
  font-size: 28px;
  font-weight: bold;
  text-align: center;
}

.btn-save-small {
  height: 50px;
  background: var(--primary);
  padding: 12px 15px;
  margin-bottom: 10px;
}

/* Sidebar Rechts */
.timetable-sidebar {
  margin-top: 120px;
  width: 320px;
  display: flex;
  flex-direction: column;
  padding: 25px;
  border-left: 1px solid rgba(255, 255, 255, 0.1);
}

.time-label {
  position: relative; /* Wichtig für das absolute Popover */
  padding: 8px;
  cursor: pointer;
}

.time-display {
  display: flex;
  flex-direction: column;
  align-items: center;
  font-size: 0.8rem;
  transition: opacity 0.2s;
}

.time-display:hover {
  opacity: 0.7;
  color: var(--primary); /* Pinker Akzent beim Hover */
}

/* Das schwebende Fenster */
.time-edit-popover {
  position: absolute;
  top: 0;
  left: 0;
  z-index: 100;
  background: var(--background);
  padding: 10px;
  border-radius: 12px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
  display: flex;
  gap: 15px;
  align-items: center;
  border: #333333;
  animation: popIn 0.2s ease-out;
}

@keyframes popIn {
  from {
    opacity: 0;
    transform: scale(0.9) translateY(10px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

.time-edit-popover input {
  border: 1px solid #eee;
  padding: 2px;
  border-radius: 4px;
  font-size: 0.9rem;
}

.close-btn {
  background: var(--primary);
  border: none;
  border-radius: 50%;
  width: 24px;
  height: 24px;
  cursor: pointer;
  color: white;
  font-weight: bold;
}

.bottom-box {
  position: absolute;
  width: calc(100% - 420px);
  bottom: 10px;
  display: flex;
  flex-wrap: wrap;
  flex-direction: column;
  row-gap: 10px;
  color: #ffffff;
  overflow-y: auto;
  opacity: 0;
  animation: kommrein 0.6s forwards;
  animation-delay: 0.6s; /* Wartet kurz, bis das Layout stabil ist */
}

@keyframes kommrein {
  from {
    opacity: 0;
    transform: translateY(10px); /* Leichte Aufwärtsbewegung */
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.glass.toolbox-bottom {
  background: rgba(255, 255, 255, 0.08) !important;
  border: 1px solid rgba(255, 255, 255, 0.2);
  padding: 15px 25px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  border-radius: 15px;
  overflow-y: auto;
}

.draggable-subject.is-dragging {
  opacity: 0.5 !important; /* Setze es auf 0.5 statt ganz weg */
  background: #224424 !important;
}

.grid-cell {
  min-height: 60px; /* Damit die Zelle auch leer groß genug ist */
  padding: 4px;
}

.truncate-text {
  display: block;
  inline-size: 100%; /* Nutzt die volle Breite der Kachel */
  white-space: nowrap; /* Kein Zeilenumbruch */
  overflow: hidden; /* Alles außerhalb der Kachel verstecken */
  text-overflow: ellipsis; /* Die magischen drei Punkte (...) */
  padding: 0 4px; /* Kleiner Sicherheitsabstand zum Rand */
  box-sizing: border-box;
  font-size: clamp(0.7rem, 2vw, 0.9rem); /* Verkleinert die Schrift dynamisch */
}

.chip-container {
  display: flex;
  flex-wrap: nowrap; /* ÄNDERUNG: Verhindert das "Flüchten" in neue Zeilen */
  gap: 4px;
  justify-content: center;
  align-items: center;
  height: 100%;
  width: 100%; /* Wichtig: Nutzt die volle Kachelbreite */
  overflow: hidden; /* Schneidet alles ab, was über die Kachel hinausgeht */
}

.subject-chip {
  flex: 1 1 45%; /* Chips versuchen 45% Breite einzunehmen (ca. 2 nebeneinander) */
  min-width: 80px; /* Aber sie werden nicht winzig */
  /* Deine restlichen Chip-Styles */
}

/* Container für die Boxen */
.staff-grid-container {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 20px;
  margin-top: 20px;
  max-height: 60vh;
  overflow-y: auto;
  padding: 10px;
}

/* Die einzelnen Boxen */
.staff-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 20px;
  border-radius: 15px;
  cursor: pointer;
  transition: all 0.3s ease;
  border: 1px solid rgba(255, 255, 255, 0.2);
  text-align: center;
  background: rgba(255, 255, 255, 0.05);
  overflow: hidden;
  aspect-ratio: 1 / 1.05;
}

.staff-box:hover {
  transform: translateY(-5px);
  background: rgba(255, 255, 255, 0.15);
  box-shadow: 0 8px 32px rgba(31, 38, 135, 0.2);
  border-color: var(--primary);
}

/* Avatar-Kreis in der Box */
.staff-avatar {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: 1.2rem;
  color: white;
  margin-bottom: 12px;
}

.staff-name {
  font-weight: 500;
  font-size: 0.85rem;
  line-height: 1.2;
  margin-top: 8px;
  width: 100%;
  /* Multi-Line Ellipsis (Standard für 2 Zeilen) */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Spezial-Style für "Neu anlegen" */
.add-new-card {
  border: 2px dashed rgba(255, 255, 255, 0.3);
}

.add-new-card .staff-avatar {
  background: rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.6);
}

.zeile {
  width: 100%;
  display: flex;
  justify-content: space-between; /* Schiebt Label nach links, Zeit-Div nach rechts */
  align-items: center;
}

.slider-head {
  display: flex;
  gap: 20px;
}

/* Modal Footer Buttons */
.btn-cancel {
  margin-top: 20px;
  padding: 10px 25px;
  border-radius: 20px;
  border: none;
  background: rgba(255, 255, 255, 0.1);
  color: white;
  cursor: pointer;
}

.kuerzel-input {
  width: 80px !important;
  text-align: center;
  letter-spacing: 1px;
}

.trash-zone {
  position: fixed;
  bottom: 30px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 9999;
  padding: 20px 40px;
  border-radius: 50px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  border: 2px dashed rgba(255, 255, 255, 0.3);
  background: rgba(255, 0, 0, 0.1);
  backdrop-filter: blur(10px);
  transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  color: white;
}

.trash-active {
  background: rgba(255, 0, 0, 0.4);
  border-color: #ff4444;
  transform: translateX(-50%) scale(1.1);
  box-shadow: 0 0 30px rgba(255, 0, 0, 0.3);
}

.trash-icon {
  font-size: 2rem;
}

/* Animation für das Erscheinen */
.bounce-enter-active {
  animation: bounce-in .5s;
}

.bounce-leave-active {
  animation: bounce-in .5s reverse;
}

@keyframes bounce-in {
  0% {
    transform: translateX(-50%) translateY(100px);
    opacity: 0;
  }
  60% {
    transform: translateX(-50%) translateY(-10px);
  }
  100% {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
  }
}

.color-picker-container {
  display: flex;
  align-items: center;
  gap: 15px;
  padding: 10px;
  border-radius: 12px;
}

.glass-color-input {
  border: none;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  cursor: pointer;
  background: none;
  padding: 0;
}

.glass-color-input::-webkit-color-swatch-wrapper {
  padding: 0;
}

.glass-color-input::-webkit-color-swatch {
  border: 2px solid rgba(255, 255, 255, 0.5);
  border-radius: 50%;
}

.color-code-display {
  font-family: monospace;
  font-weight: bold;
  color: #444;
}

/* Sidebar Details */
.tafel-card {
  margin-bottom: 20px;
  position: relative; /* Damit absolute Kinder (Tooltip) hier "andocken" */
  overflow: visible; /* Damit der Tooltip über den Rand schweben darf */
}

.tafel-card {
  transition: all 0.3s ease;
  border-left: 4px solid transparent;
}

/* Spezial-Style für Fächer wie "Tanzen" */
.tafel-card.extra-fach {
  background: rgba(169, 169, 169, 0.1);
  border-left-color: #A9A9A9;
}

/* Animation wenn ein Fach dazu kommt */
.tafel-card {
  animation: slideIn 0.3s ease-out;
}

/* Karten-Styling */
.tafel-card:hover {
  background-color: rgba(255, 255, 255, 0.03); /* Fast unsichtbar */
  border-radius: 6px;
  transition: all 0.2s ease-in-out;
  cursor: help; /* Weil du ein Tooltip hast */

  /* Ein ganz feiner Glow-Effekt */
  box-shadow: inset 0 0 10px rgba(255, 255, 255, 0.02);
}

.small-sidebar-input {
  width: 45px !important;
  height: 28px !important;
  padding: 2px 5px !important;
  margin: 0 !important;
  font-size: 0.8rem !important;
  text-align: center;
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid var(--primary) !important;
}

.edit-toggle-btn {
  background: transparent;
  border: 1px solid var(--primary);
  color: var(--primary);
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 0.7rem;
  cursor: pointer;
  margin-bottom: 10px;
}

.edit-toggle-btn:hover {
  background: var(--primary);
  color: white;
}

.add-fach-btn {
  width: 100%;
  background: rgba(255, 255, 255, 0.05);
  border: 1px dashed rgba(255, 255, 255, 0.3);
  color: #ccc;
  padding: 8px;
  margin-top: 10px;
  cursor: pointer;
  border-radius: 8px;
}

/* Header-Styling */
.tafel-meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
  font-size: 0.6rem;
  color: var(--text-dim);
  text-transform: uppercase;
  letter-spacing: 1px;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateX(20px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

/* Erzwingt 3 Spalten im vorhandenen Container */
.stundentafel-grid {
  display: grid !important;
  grid-template-columns: repeat(5, 1fr) !important;
  gap: 10px !important;
  max-height: 400px;
  overflow-y: auto;
  padding: 5px;
  width: 100%;
}

/* Zeit-Eingabe nebeneinander */
.time-input-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.time-input-row .input-floating-group {
  flex: 1;
  margin-top: 0;
}

.time-separator {
  font-size: 1.5rem;
  font-weight: bold;
  color: var(--primary);
  padding-bottom: 5px;
}

.hint-text {
  font-size: 0.75rem;
  color: #aaa;
  margin-top: 8px;
  text-align: right;
}

/* Hervorhebung der Auswahl */
.active-selection {
  border: 2px solid var(--primary) !important;
  background: rgba(var(--primary), 0.2) !important;
  transform: scale(1.02);
}

.total-stats-footer {
  margin-top: 20px;
  padding: 15px;
  background: rgba(255, 255, 255, 0.08) !important;
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 12px;
}

.stats-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.stats-label {
  font-weight: bold;
  font-size: 0.9rem;
  color: #fff;
}

.badge {
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 0.8rem;
  margin-left: 5px;
}

.badge.ist {
  background: rgba(46, 213, 115, 0.2);
  color: #2ed573;
}

.badge.soll {
  background: rgba(255, 255, 255, 0.1);
  color: #ccc;
}

.total-progress-bar {
  height: 6px;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 5px;
}

.total-progress-fill {
  height: 100%;
  transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

.total-percentage {
  font-size: 0.75rem;
  text-align: right;
  color: #aaa;
}

.tafel-header-row {
  display: flex;
  /* Das hier ist der entscheidende Teil: */
  justify-content: space-between;
  align-items: center;
  width: 100%; /* Sicherstellen, dass die Zeile die volle Breite nutzt */
  margin-bottom: 8px;
  overflow: visible !important;
}

.badges {
  font-size: 0.6rem;
  display: flex;
  justify-content: center;
  gap: 4px;
}

.fach-title {
  font-weight: 600;
  /* Verhindert, dass ein langer Name das Badge wegdrückt: */
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding-right: 10px;
  cursor: help;
}

/* Diensteinsatzplan: Einsatzort-Hinweis im Termin-Modal */
.einsatzort-hint {
  font-size: 0.85rem;
  opacity: 0.85;
  margin-bottom: 8px;
}

/* Diensteinsatzplan-Tooltip darf umbrechen (längere Aufschlüsselung).
   Zwei Klassen => höhere Spezifität als .tafel-header-row::after, damit das
   nowrap der Basisregel sicher überschrieben wird. */
.tafel-header-row.dienst-tafel-row::after {
  white-space: normal;
  width: max-content;
  max-width: 220px;
  height: auto;
  text-align: left;
  line-height: 1.35;
  word-break: break-word;
  overflow-wrap: anywhere;
}

/* Der Tooltip-Container beim Hovern */
.tafel-header-row:hover::before {
  content: '';
  position: absolute;
  bottom: 115%; /* Direkt unter den Tooltip */
  left: 50%;
  transform: translateX(-50%);
  border-width: 6px;
  border-style: solid;
  border-color: rgba(30, 30, 30, 0.98) transparent transparent transparent;
  z-index: 9999;
  opacity: 1;
  transition: opacity 0.2s ease-out;
}

/* 1. Grundzustand des Tooltips (unsichtbar) */
.tafel-header-row::after {
  content: attr(data-tooltip);
  position: absolute;
  bottom: 100%; /* Startet etwas tiefer */
  left: 50%;
  transform: translateX(-50%) translateY(5px); /* Kleiner Versatz nach unten */
  background: rgba(30, 30, 30, 0.98);
  color: #fff;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 0.85rem;
  white-space: nowrap;
  z-index: 9999;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.4);
  border: 1px solid rgba(255, 255, 255, 0.1);
  pointer-events: none;

  /* Die Animationseigenschaften */
  opacity: 0;
  transition: opacity 0.2s ease-out, transform 0.2s ease-out;
  visibility: hidden; /* Verhindert Interaktion im unsichtbaren Zustand */
}

/* 2. Zustand beim Hover (sichtbar) */
.tafel-header-row:hover::after {
  opacity: 1;
  visibility: visible;
  bottom: 125%; /* Schwebt nach oben an die Zielposition */
  transform: translateX(-50%) translateY(0);
}

.fach-stats-badge {
  background: rgba(255, 255, 255, 0.1);
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 0.8rem;
  font-family: 'monospace';
  color: white;
  flex-shrink: 0;
  display: flex;
  gap: 4px;
  border: 1px solid rgba(255, 255, 255, 0.05);
}

.timetable-sidebar {
  padding: 1.5rem;
  color: #fff;
}

.fach-title {
  font-size: 0.8rem;
  flex: 1;
  text-transform: initial;
}


/* Footer & Progress Bar */
.total-stats-footer {
  margin-top: 2rem;
  padding: 1.5rem;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.03);
}

.stats-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.stats-label {
  font-size: 1.1rem;
  font-weight: bold;
}

.total-progress-bar {
  height: 8px;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 0.5rem;
}

.total-progress-fill {
  transition: width 0.5s ease-out;
  height: 100%;
  box-shadow: 0 0 10px rgba(46, 213, 115, 0.3);
}

.total-percentage {
  text-align: right;
  font-size: 0.8rem;
  color: var(--text-dim);
}

/* Add-Button */
.add-fach-btn {
  width: 100%;
  margin-top: 1rem;
  padding: 12px;
  background: transparent;
  border: 2px dashed var(--glass-border);
  color: var(--text-dim);
  border-radius: 12px;
  transition: all 0.3s;
}

.add-fach-btn:hover {
  border-color: var(--accent-green);
  color: #fff;
  background: rgba(46, 213, 115, 0.1);
}

.room-grid-layout-wrapper {
  display: grid;
  /* 1. Spalte fix für Zeit (z.B. 60px), dann 5 gleich große Spalten für die Tage */
  grid-template-columns: 60px repeat(5, 1fr);
  position: relative;
  background: #1a1a1a;
  border: 1px solid #333;
  min-height: 800px; /* Wichtig für die absolute Positionierung */
  height: 100vw;
}

.day-column {
  position: relative; /* ANKER: Das verhindert, dass Chips nach links oder unten abhauen */
  border-right: 1px solid #333;
  height: 100%;
}

.room-time-label {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.room-time-range {
  display: flex;
  width: 100%;
  font-size: 0.9rem;
  color: #a0a0a0;
  justify-content: center;
}

.subject-chip-absolute {
  position: absolute; /* Bezieht sich jetzt auf .day-column */
  left: 5%;
  width: 90%;
  border-radius: 6px;
  background: var(--primary);
  padding: 8px;
  color: white;
  z-index: 10;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
  display: -webkit-box;
  -webkit-line-clamp: 2; /* Zeigt maximal 2 Zeilen */
  -webkit-box-orient: vertical;
  line-height: 1.2em;
  max-height: 100%; /* Verhindert das Überlappen */
  overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
}

.subject-chip-absolute:hover {
  z-index: 999 !important;
  height: auto; /* Erweitert die Box, falls gewünscht */
  min-height: 80px;
  transform: scale(1.02);
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
  -webkit-line-clamp: unset; /* Textbegrenzung aufheben */
  display: block; /* Falls -webkit-box das Layout stört */
}

.room-time-header, .room-day-header {
  display: flex;
  align-items: center; /* Vertikale Zentrierung */
  justify-content: center; /* Horizontale Zentrierung */
  height: 50px; /* Gib hier deine gewünschte Header-Höhe an */
  padding: 5px 0; /* Optional: Etwas "Atmenraum" */
  text-transform: uppercase;
  font-size: 0.8rem;
  font-weight: 600;
  color: #aaa; /* Hier kannst du wieder deine :root Variablen nutzen */
}

.overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  background: rgba(0, 0, 0, 0.5); /* Abdunkelung */
  backdrop-filter: blur(5px); /* Schicker Unschärfe-Effekt */
  display: flex; /* WICHTIG: Erlaubt Zentrierung */
  align-items: center; /* Zentriert vertikal */
  justify-content: center; /* Zentriert horizontal */
  z-index: 90;
}

.lehrerstundenplan {
  margin-bottom: 10px;
}

.custom-option.active-opt {
  background: rgba(255, 255, 255, 0.2);
  border-left: 3px solid var(--primary); /* Ein kleiner Akzent am Rand */
  color: white;
}

.select-row-layout {
  display: flex !important;
  flex-direction: row !important;
  align-items: center;
  justify-content: flex-start; /* Alles beginnt links */
  gap: 8px; /* Das ist dein "Leerzeichen" (flexibler als Text) */
  padding: 10px 15px;
  width: 100%;
}

.check-icon-right {
  /* margin-left: auto;  <-- DAS MUSS RAUS! */
  color: #4ade80;
  font-weight: bold;
  flex-shrink: 0;
  display: inline-block;
}

.text-btn-tiny {
  background: none;
  border: none;
  color: var(--primary);
  font-size: 0.75rem;
  cursor: pointer;
  padding: 2px 8px;
  border-radius: 4px;
  transition: background 0.2s;
}

.text-btn-tiny:hover {
  background: rgba(255, 255, 255, 0.1);
}

/* Verhindert, dass das Dropdown im Modal abgeschnitten wird */
.modal-body {
  overflow: visible !important;
}

.option-name {
  flex-grow: 1; /* Optional: Lässt den Namen den Platz füllen */
  white-space: nowrap; /* Verhindert Zeilenumbruch im Namen */
}

.lehrer-timetable-scroll-area {
  display: flex; /* NEU: Macht die Area selbst zum Flex-Container */
  flex-direction: column;
  flex: 1;
  overflow-y: auto;
  height: 75vh;
  border-radius: 20px;
  border: 1px solid rgba(255, 255, 255, 0.05);
  margin-bottom: 200px;
}

.duration-picker {
  display: flex;
  align-items: center;
  gap: 10px;
  background: rgba(255, 255, 255, 0.1);
  padding: 15px;
  border-radius: 12px;
  justify-content: center;
}

.time-input-field {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.time-input-field input {
  width: 60px;
  background: rgba(255, 255, 255, 0.2);
  border: 1px solid rgba(255, 255, 255, 0.3);
  color: white;
  font-size: 1.2rem;
  padding: 8px;
  border-radius: 8px;
  text-align: center;
}

.time-input-divider {
  font-size: 1.5rem;
  font-weight: bold;
  padding-bottom: 20px;
}

.custom-select-container {
  position: relative; /* Wichtig für die Positionierung */
}

.custom-lehrer-options {
  display: flex;
  flex-direction: column;
  position: absolute;
  top: calc(100% + 5px); /* Standardmäßig unten */
  left: 0;
  right: 0;
  background: rgba(30, 30, 30, 0.98);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  z-index: 9999;
  max-height: 250px;
  overflow-y: auto;
}

/* Das Menü öffnet sich nach oben */
.custom-lehrer-options.dropup-mode {
  /* 1. Positionierung komplett umkehren */
  top: auto !important; /* Zwingend das top: 100% überschreiben */
  bottom: calc(100% + 5px); /* Über den Trigger setzen mit 5px Abstand */

  /* 2. Dimensionen sicherstellen */
  display: flex;
  flex-direction: column;
  min-width: 100%; /* Nimmt die volle Breite des Wrappers ein */
  width: max-content; /* Falls der Text länger ist, darf es breiter werden */
  max-height: 250px;

  /* 3. Sichtbarkeit */
  z-index: 9999;
  overflow-y: auto; /* Scrollbar, falls die 6 Räume zu viel Platz brauchen */

  /* 4. Ästhetik (Glassmorphism beibehalten) */
  background: rgba(30, 30, 30, 0.98);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  padding: 8px 0;
}

/* Pfeil-Rotation für Dropup */
.arrow-up {
  transition: transform 0.3s;
  display: inline-block;
}

.arrow-up.rotate {
  transform: rotate(180deg);
}

/* --- elli-Dialog (Ersatz fuer native confirm()/alert()) --- */
.elli-dialog-overlay {
  position: fixed;
  inset: 0;
  z-index: 10000;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.elli-dialog {
  background: #1f1f1f;
  color: #ffffff;
  border-radius: 16px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
  width: 100%;
  max-width: 420px;
  padding: 24px 26px;
}

.elli-dialog-title {
  font-size: 1.15rem;
  font-weight: 600;
  margin-bottom: 10px;
  color: #ffffff;
}

.elli-dialog-message {
  font-size: 0.98rem;
  line-height: 1.55;
  color: #e6e6e6;
  white-space: pre-wrap;
}

.elli-dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 24px;
}

.elli-dialog-btn {
  padding: 9px 20px;
  border-radius: 10px;
  border: none;
  cursor: pointer;
  font-size: 0.95rem;
  font-weight: 500;
  transition: background 0.15s, transform 0.05s;
}

.elli-dialog-btn.primary {
  background: #4f8cff;
  color: #ffffff;
}

.elli-dialog-btn.primary:hover {
  background: #3f78e0;
}

.elli-dialog-btn.secondary {
  background: #3a3a3a;
  color: #e0e0e0;
}

.elli-dialog-btn.secondary:hover {
  background: #474747;
}

.elli-dialog-btn:active {
  transform: translateY(1px);
}
</style>
<script setup>
import {ref} from 'vue';
import {computed} from 'vue';

const days = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
const editingId = ref(null); // Speichert, welche Zeile gerade offen ist

const finishEditing = (stunde) => {
  if (!stunde || !stunde.start || !stunde.ende) {
    editingId.value = null; // .value ist wichtig!
    return;
  }

  const index = zeitRaster.value.findIndex(s => s.id === stunde.id);
  if (index === -1) return;

  // Kaskadierung
  for (let i = index + 1; i < zeitRaster.value.length; i++) {
    const vorherige = zeitRaster.value[i - 1];
    const aktuelle = zeitRaster.value[i];

    // Entferne das 'if'. Die Kaskadierung soll immer greifen.
    const dauer = getDiffInMinutes(aktuelle.start, aktuelle.ende);

    // Die aktuelle Stunde rückt direkt an das Ende der vorherigen
    aktuelle.start = vorherige.ende;
    aktuelle.ende = addMinutesToTime(aktuelle.start, dauer);
  }

  editingId.value = null; // Schließt das Popover
};

const raumZeitRaster = computed(() => {
  const raster = [];
  const startStunde = 7;
  const endStunde = 18;

  for (let i = startStunde; i < endStunde; i++) {
    // Formatiert die Zahl zu "08:00"
    const start = `${i.toString().padStart(2, '0')}`;
    // Formatiert die nächste Stunde zu "09:00"
    const ende = `${(i + 1).toString().padStart(2, '0')}`;

    raster.push({
      id: i,
      start: start,
      ende: ende
    });
  }
  return raster;
});

const getDiffInMinutes = (start, ende) => {
  const [h1, m1] = start.split(':').map(Number);
  const [h2, m2] = ende.split(':').map(Number);
  return (h2 * 60 + m2) - (h1 * 60 + m1);
};

const addMinutesToTime = (time, mins) => {
  const [h, m] = time.split(':').map(Number);
  const date = new Date();
  date.setHours(h, m + mins);
  return date.toTimeString().slice(0, 5);
};

const generateDefaultRaster = () => {
  let start = 8 * 60 + 15;
  return Array.from({length: 10}, (_, i) => ({
    id: i + 1,
    start: formatTime(start + i * 45),
    ende: formatTime(start + (i + 1) * 45)
  }));
};

function formatTime(mins) {
  const h = Math.floor(mins / 60).toString().padStart(2, '0');
  const m = (mins % 60).toString().padStart(2, '0');
  return `${h}:${m}`;
}

const zeitRaster = ref(generateDefaultRaster());
defineExpose({
  zeitRaster
});
</script>
<script>
import {computed, ref} from "vue";

// Standard: gleiche Herkunft wie das ausgelieferte Frontend (Docker/Prod).
// Für lokale Entwicklung via .env(.local) überschreibbar: VITE_API_URL=http://192.168.178.52:8080/api.php
const API_URL = import.meta.env.VITE_API_URL || '/api.php'

import iconAktivitaet from '@/assets/icons/aktivitaet.svg'
import iconErstkraft from '@/assets/icons/erstkraft.svg'
import iconRaum from '@/assets/icons/raum.svg'
import iconSchulfach from '@/assets/icons/schulfach.svg'
import iconZweitkraft from '@/assets/icons/zweitkraft.svg'
import iconDiensteinsatzplan from '@/assets/icons/diensteinsatzplan.svg'
import iconGesamtplan from '@/assets/icons/gesamtplan.svg'
import iconLehrerstundenplan from '@/assets/icons/lehrerstundenplan.svg'
import iconRaumbelegungsplan from '@/assets/icons/raumbelegungsplan.svg'
import iconSchuelerstundenplan from '@/assets/icons/schuelerstundenplan.svg'

const getInitialColor = () => {
  const h = Math.floor(Math.random() * 360);
  const s = Math.floor(Math.random() * 20) + 60;
  const l = Math.floor(Math.random() * 10) + 35;

  // Hilfsfunktion zur Umrechnung (lokal definiert)
  const f = (n) => {
    const k = (n + h / 30) % 12;
    const a = s * Math.min(l, 100 - l) / 100;
    const color = l / 100 - a / 100 * Math.max(-1, Math.min(k - 3, 9 - k, 1));
    return Math.round(255 * color).toString(16).padStart(2, '0');
  };

  return `#${f(0)}${f(8)}${f(4)}`;
};

export default {
  data() {
    return {
      view: 'home',
      nutzerName: localStorage.getItem('nutzerName') || 'Nutzer',
      isHardwareBack: false,
      showSettings: false,
      showTypeDropdown: false,
      showRaumDropdown: false,
      showVerantDropdown: false,
      showTagDropdown: false,
      activeDropdown: null,
      activeTagIndex: null,
      activeVerantIndex: null, // Für das Verantwortlichen-Dropdown
      activeRaumIndex: null,   // Für das Raum-Dropdown
      currentSchuljahr: '25/26',
      activeCategory: '',
      schule: {adresse: {name: '', strasse: '', stadt: ''}},
      days: ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'],
      timeSlots: ['07:45', '08:30', '09:15', '10:15', '11:00', '11:45', '12:45', '13:30', '14:15', '15:00', '15:45'],
      elements: ['Mathe', 'Deutsch', 'Sport', 'AG Kunst'],
      saveTimeout: null, // Für das Debouncing der Adresse,
      isInitialLoading: false,
      categoryMap: {
        'aktivitaet': {
          plural: 'Aktivitäten',
          icon: iconAktivitaet
        },
        'erstkraft': {
          plural: 'Erstkräfte',
          icon: iconErstkraft
        },
        'raum': {
          plural: 'Räume',
          icon: iconRaum
        },
        'schulfach': {
          plural: 'Schulfächer',
          icon: iconSchulfach
        },
        'zweitkraft': {
          plural: 'Zweitkräfte',
          icon: iconZweitkraft
        },
        'diensteinsatzplan': {
          plural: 'Diensteinsatzpläne',
          icon: iconDiensteinsatzplan
        },
        'gesamtplan': {
          plural: 'Gesamtplan',
          icon: iconGesamtplan
        },
        'lehrerstundenplan': {
          plural: 'Lehrerstundenpläne',
          icon: iconLehrerstundenplan
        },
        'raumbelegungsplan': {
          plural: 'Raumbelegungspläne',
          icon: iconRaumbelegungsplan
        },
        'schuelerstundenplan': {
          plural: 'Schülerstundenpläne',
          icon: iconSchuelerstundenplan
        }
      },
      currentActivity: {
        id: null,
        name: '',
        typ: '',
        einsatzort: '',
        termine: []
      },
      erstkraefte: [],
      zweitkraefte: [],
      raeume: [],
      showRaumModal: false,
      editingRaum: {
        name: '',
        unterrichtsfach: '',
        verfuegbarkeiten: []
      },
      aktivitaeten: [],
      einsatzorte: [],
      snackbar: {
        show: false,
        message: '',
        type: 'success' // 'success' oder 'error'
      },
      schuljahre: [],
      currentSchuljahrId: null,
      // In data() von App.vue hinzufügen:
      currentErstkraft: {
        id: null,
        name: '',
        titel: '',
        kuerzel: '',
        farbe: getInitialColor(),
        pflichtstunden: 26,
        ermaessigung: 0,
        upz: 0,
        faecher: ''
      },
      currentZweitkraft: {
        id: null,
        schuljahr_id: this.currentSchuljahrId,
        name: '',
        kuerzel: '',
        typ: 'Kinderpfleger:in', // Standard-Typ
        einsatzort: '',
        farbe: getInitialColor(),
        textfarbe: '#ffffff',
        pflichtstunden_masse: [{einsatzort: 'IB Schule', stunden: 20}],
        ermaessigung: 0,
        upz: 20,
        grund_ermaessigung: '',
      },
      currentSchuelerStundenPlan: {
        id: null,
        klasse_name: null,
        schuljahr_id: this.currentSchuljahrId,
        termine: []
      },
      showPersonModal: false,
      showLehrerModal: false,
      pendingAssignment: null,
      editingPerson: {name: '', type: ''},
      personModalType: 'erstkraft',
      raum_verfuegbarkeiten: [],
      faecher: [],
      schuelerstundenplaene: [],
      showFachModal: false,
      editingFach: {name: '', farbe: getInitialColor(), benoetigte_raeume: []},
      showNewFachModal: false,
      importResults: [],
      showImportPreview: false,
      showOnboardingModal: false,
      onboarding: {schuljahr: '25/26', schulname: ''},
      onboardingSaving: false,
      // Eigener elli-Dialog statt nativer confirm()/alert()-Fenster
      dialog: {show: false, mode: 'alert', title: '', message: '', okText: 'OK', cancelText: 'Abbrechen', _resolve: null},
      dragOverCell: null,
      draggedSubject: null,
      assignments: [],
      verfuegbareFaecher: [],
      draggingId: null,
      planData: [],
      isDragging: false,
      isOverTrash: false,
      stundentafel: [],
      stundentafelDefinition: [],
      isEditingTafel: false,
      showTafelSelectionModal: false,
      selectedFachId: null,
      tempSoll: null,
      selectedFachName: null, // Der Name (unser neuer eindeutiger Schlüssel für das UI)
      selectedUniqueKey: null,
      selectedType: null,
      newTafelEntry: {
        name: '',
        farbe: '',
        soll_klassenverbund: 0, // für Schüler
        soll_differenzierung: 0, // für Schüler
        soll_stunden_klassenverbund: 0,        // für Lehrer
        soll_minuten_klassenverbund: 0,         // für Lehrer
        soll_stunden_differenzierung: 0,        // für Lehrer
        soll_minuten_differenzierung: 0,         // für Lehrer
      },
      tempHours: null,
      tempMinutes: null,
      sliderSteps: 0,
      sliderSteps_differenzierung: 0,
      activeRaumId: null,
      raumVerfuegbarkeiten: [],
      lehrer: [],
      lehrerVerfuegbarkeiten: [],
      returnToFachAfterAddFach: false,
      activeLehrerId: null,
      activeLehrerMap: [],
      activeLehrer: [],
      showModalUhrzeit: false,
      selectedTime: '08:00',
      showLehrerPlanModal: false,
      showDienstPlanModal: false,
      isNewKlasse: false,
      klassen: [], // Hier sollten deine geladenen Klassen rein
      lehrerPlanForm: {
        klassen_id: null,
        newKlassenName: '',
        start: '08:00',
        ende: '09:30',
        tag: '',
        is_differenzierung: false,
        draggedItem: null,
        itemType: '' // 'schulfach' oder 'aktivitaet'
      },
      editingAktivitaet: {
        name: '',
        typ: '',
        termine: [
          {
            tag: '',
            uhrzeit: '', // Wichtig: Gleicher Name wie im v-model!
            endzeit: '',
            raeume: [],  // Muss als Array existieren wegen .includes()
            verantwortliche: []
          }
        ]
      },
      showNewAktivitaetModal: false,
      currentLehrerstundenplan: {},
      tag: '',
      start: '',
      ende: '',
      einheiten_kv: 0,
      einheiten_diff: 0, // Wichtig für den Slider-Startwert
      typ: 'a',
      tempDauer: 45,
      stundenAuswahl: 1,
      openDropupId: null,
      klassenVerfuegbarkeiten: [],
      currentDiensteinsatzplan: {},
      activeZweitkraftId: '',
    }
  },
  computed: {
    get_soll_klassenverbund() {
      if (this.activeCategory === 'lehrerstundenplan') {
        this.newTafelEntry.soll_klassenverbund = (this.einheiten_kv * 45 / 60);
        return (this.einheiten_kv * 45 / 60);
      }
    },
    get_soll_differenzierung() {
      if (this.activeCategory === 'lehrerstundenplan') {
        this.newTafelEntry.soll_differenzierung = (this.einheiten_diff * 45 / 60);
        return (this.einheiten_diff * 45 / 60);
      }
    },
    isFormValid() {
      const nameValid = this.currentActivity.name.trim() !== '';
      const termineValid = this.currentActivity.termine.length > 0 &&
          this.currentActivity.termine.every(t =>
              t.verantwortliche.length > 0 &&
              t.raeume.length > 0 &&
              t.uhrzeit < t.endzeit // PRÜFUNG: Start muss vor Ende liegen
          );
      return nameValid && termineValid;
    },
    isErstkraftFormValid() {
      return (
          this.currentErstkraft.name &&
          this.currentErstkraft.name.trim().length > 0 &&
          this.currentErstkraft.kuerzel &&
          this.currentErstkraft.kuerzel.trim().length > 0
      );
    },
    isZweitkraftFormValid() {
      return (
          this.currentZweitkraft.name &&
          this.currentZweitkraft.name.trim().length > 0 &&
          this.currentZweitkraft.kuerzel &&
          this.currentZweitkraft.kuerzel.trim().length > 0 &&
          this.currentZweitkraft.typ // Sicherstellen, dass ein Beruf gewählt wurde
      );
    },
    isRaumFormValid() {
      return (
          this.editingRaum.name &&
          this.editingRaum.name.trim().length > 0
      );
    },
    currentItems() {
      if (this.activeCategory === 'aktivitaet') return this.aktivitaeten;
      if (this.activeCategory === 'raum') return this.raeume;
      if (this.activeCategory === 'erstkraft') return this.erstkraefte;
      if (this.activeCategory === 'zweitkraft') return this.zweitkraefte;
      if (this.activeCategory === 'schulfach') return this.faecher;
      if (this.activeCategory === 'schuelerstundenplan') return this.schuelerstundenplaene;
      if (this.activeCategory === 'raumbelegungsplan') return this.raumVerfuegbarkeiten;
      if (this.activeCategory === 'lehrerstundenplan') return this.erstkraefte;
      if (this.activeCategory === 'diensteinsatzplan') return this.zweitkraefte;
      return [];
    },
    calculatedUPZZweitkraft() {
      // 1. Sicherheitsscheck: Existiert das Objekt und das Array?
      if (!this.currentZweitkraft || !this.currentZweitkraft.pflichtstunden_masse) {
        return 0;
      }

      // 2. Summe der Stunden berechnen
      const gesamtPflicht = this.currentZweitkraft.pflichtstunden_masse.reduce((sum, mass) => {
        // Sicherstellen, dass 'stunden' eine Zahl ist (verhindert NaN)
        const wert = parseFloat(mass.stunden) || 0;
        return sum + wert;
      }, 0);

      // 3. Ermäßigung abziehen
      const ermaessigung = parseFloat(this.currentZweitkraft.ermaessigung) || 0;

      // 4. Ergebnis zurückgeben (auf 2 Nachkommastellen gerundet)
      return (gesamtPflicht - ermaessigung).toFixed(2);
    },
    totalSoll() {
      return this.dynamicStundentafel.reduce((sum, item) => sum + (item.soll_differenzierung + item.soll_klassenverbund || 0), 0);
    },
    // NEU: Berechnet die Summe aller Ist-Stunden im Plan
    totalIst() {
      return this.dynamicStundentafel.reduce((sum, item) => sum + (item.ist_differenzierung + item.ist_klassenverbund || 0), 0);
    },
    dynamicStundentafel() {
      const counts = {};

      // 1. Datenerfassung: Wir zählen, was AKTUELL im Plan liegt
      const termine = this.currentSchuelerStundenPlan?.termine || [];
      termine.forEach(t => {
        // Robustes Namens-Matching: Toolbox-Fächer nutzen oft 'fachName' oder 'aktivitaet_name'
        const name = t.fachName || t.aktivitaet_name || (t.display ? t.display.fachName : null);
        if (name) {
          if (!counts[name]) counts[name] = {kv: 0, diff: 0};
          if (t.is_differenzierung) counts[name].diff++;
          else counts[name].kv++;
        }
      });


      // Wir arbeiten mit einer Kopie der gezählten Stunden, um "Wildcards" zu identifizieren
      const tempCounts = {...counts};

      // 2. Mapping: Wir gehen zuerst die definierte Stundentafel durch
      const result = this.stundentafel.map(sollFach => {
        const istData = tempCounts[sollFach.name] || {kv: 0, diff: 0};
        // Aus tempCounts löschen, damit wir wissen, dass dieses Fach "erledigt" ist
        delete tempCounts[sollFach.name];

        return {
          ...sollFach,
          ist_klassenverbund: istData.kv,
          ist_differenzierung: istData.diff,
          ist: istData.kv + istData.diff
        };
      });

      // 3. WILDCARDS (Fächer aus der Toolbox):
      // Alles, was jetzt noch in tempCounts ist, wurde im Plan gefunden, steht aber NICHT in der Stundentafel.
      Object.keys(tempCounts).forEach(name => {
        const istData = tempCounts[name];
        result.push({
          id: 'wildcard-' + name,
          name: name,
          soll: 0,               // Wichtig: 0 Soll-Stunden für Toolbox-Fächer
          soll_klassenverbund: 0,
          soll_differenzierung: 0,
          ist_klassenverbund: istData.kv,
          ist_differenzierung: istData.diff,
          ist: istData.kv + istData.diff,
          isWildcard: true,
          farbe: '#9e9e9e'       // Neutrale Farbe für Fächer außerhalb der Tafel
        });
      });

      return result;
    },
    totalSoll_Lehrer() {
      return this.dynamicLehrerstundentafel.reduce((sum, item) => sum + (item.soll_differenzierung + item.soll_klassenverbund || 0), 0);
    },
    // NEU: Berechnet die Summe aller Ist-Stunden im Plan
    totalIst_Lehrer() {
      return this.dynamicLehrerstundentafel.reduce((sum, item) => sum + (item.ist_differenzierung + item.ist_klassenverbund || 0), 0);
    },
    dynamicLehrerstundentafel() {
      const counts = {};
      const sollVorgaben = this.currentLehrerstundenplan.lehrer_stundentafel || [];

      sollVorgaben.forEach(soll => {
        const key = soll.bezeichnung;
        if (!counts[key]) {
          counts[key] = {
            aktivitaet_id: soll.aktivitaet_id || null,
            fach_id: soll.fach_id || null,
            name: key, // Wir speichern den Namen direkt im Objekt
            soll_klassenverbund: 0,
            soll_differenzierung: 0,
            ist_klassenverbund: 0,
            ist_differenzierung: 0
          };
        }

        // FALL 1: Daten kommen im "neuen" Format (direkt als Zahl)
        if (soll.soll_klassenverbund !== undefined || soll.soll_differenzierung !== undefined) {
          counts[key].soll_klassenverbund += parseFloat(soll.soll_klassenverbund || 0);
          counts[key].soll_differenzierung += parseFloat(soll.soll_differenzierung || 0);
        }
        // FALL 2: Daten kommen im "alten" Format (mit besetzung und soll_stunden)
        else if (soll.besetzung === 'doppel') {
          counts[key].soll_differenzierung += parseFloat(soll.soll_stunden || 0);
        } else {
          counts[key].soll_klassenverbund += parseFloat(soll.soll_stunden || 0);
        }
      });

      const termine = this.currentLehrerstundenplan.termine || [];
      termine.forEach(t => {
        const key = t.aktivitaet || t.fach;

        if (key) {
          if (!counts[key]) {
            counts[key] = {
              aktivitaet_id: t.aktivitaet_id || null, fach_id: t.fach_id || null, name: t.aktivitaet || t.fach,
              soll_klassenverbund: 0, soll_differenzierung: 0, ist_klassenverbund: 0, ist_differenzierung: 0
            };
          }

          let dauer = t.fach ? 0.75 : 0;
          if (t.start && t.ende) {
            const [hStart, mStart] = t.start.split(':').map(Number);
            const [hEnde, mEnde] = t.ende.split(':').map(Number);
            dauer = (hEnde * 60 + mEnde - (hStart * 60 + mStart)) / 60;
          }

          if (t.is_differenzierung || t.besetzung === 'doppel') {
            counts[key].ist_differenzierung += dauer;
          } else {
            counts[key].ist_klassenverbund += dauer;
          }
        }
      });

      console.log("counts", counts);
      // WICHTIG: In ein Array umwandeln für v-for
      return Object.values(counts).sort((a, b) => a.name.localeCompare(b.name));
    },
    getSelectedKlassenName() {
      // Falls keine ID gewählt wurde, brich sofort ab
      if (!this.lehrerPlanForm.klassen_id) return null;

      // Suche im Array 'schuelerstundenplaene'
      const plan = this.schuelerstundenplaene.find(p => {
        // Nutze == um String/Number Konflikte zu vermeiden
        return p.id == this.lehrerPlanForm.klassen_id;
      });

      return plan ? (plan.name || plan.klasse_name) : 'Unbekannte Klasse';
    },
    aktivitaetenMitFarbe() {
      if (!this.aktivitaeten) return [];

      return this.aktivitaeten.map(a => {
        // Wir speichern die Farbe direkt im Objekt
        const farbe = this.getFachFarbe(a.name) || this.getRandomPastelColor(a.name);
        return {
          ...a,
          farbe: farbe
        };
      });
    },
    // SOLL-Stunden je Einsatzort aufsummiert (Budget des Einsatzorts).
    sollByEinsatzort() {
      const sollListe = (this.currentDiensteinsatzplan || {}).stundentafel || [];
      const map = {};
      sollListe.forEach(s => {
        const ort = (s.einsatzort && String(s.einsatzort).trim()) ? s.einsatzort : 'Ohne Einsatzort';
        map[ort] = (map[ort] || 0) + parseFloat(s.soll_stunden || 0);
      });
      return map;
    },
    // Diensteinsatzplan-Stundentafel: flache Liste je Aktivität (wie die
    // Lehrertafel). Es gibt KEINE reinen Einsatzort-Zeilen mehr – das SOLL-Budget
    // des Einsatzorts wird als SOLL der Aktivität(en) dieses Einsatzorts gezeigt.
    // IST kommt aus den Terminen; der Hover-Tooltip schlüsselt es auf.
    dynamicDiensteinsatztafel() {
      const plan = this.currentDiensteinsatzplan || {};
      const sollListe = plan.stundentafel || [];
      const termine = plan.termine || [];
      const rows = {};

      const round = (n) => Math.round(n * 100) / 100;
      const ortLabel = (ort) => (ort && String(ort).trim()) ? ort : 'Ohne Einsatzort';

      const ensureRow = (name, einsatzort, id) => {
        const key = (name && String(name).trim()) ? name : ortLabel(einsatzort);
        if (!rows[key]) {
          rows[key] = { name: key, aktivitaet_id: id ?? null, einsatzort: einsatzort || null, ist: 0 };
        } else if (!rows[key].einsatzort && einsatzort) {
          rows[key].einsatzort = einsatzort;
        }
        return rows[key];
      };

      // Nur echte Aktivitäten werden zu Zeilen:
      // a) Aktivitäten mit eigener Stundentafel-Zeile (aktivitaet_id gesetzt)
      sollListe.forEach(s => {
        if (s.aktivitaet_id || (s.aktivitaet && String(s.aktivitaet).trim())) {
          ensureRow(s.aktivitaet, s.einsatzort, s.aktivitaet_id);
        }
      });
      // b) Aktivitäten aus den Terminen (liefern die IST-Stunden)
      termine.forEach(t => {
        let dauer = 0;
        if (t.start && t.ende) {
          const [hStart, mStart] = t.start.split(':').map(Number);
          const [hEnde, mEnde] = t.ende.split(':').map(Number);
          dauer = (hEnde * 60 + mEnde - (hStart * 60 + mStart)) / 60;
        }
        ensureRow(t.aktivitaet, t.einsatzort, t.aktivitaet_id).ist += dauer;
      });

      const sollMap = this.sollByEinsatzort;

      return Object.values(rows)
          .map(r => {
            const ort = ortLabel(r.einsatzort);
            const soll = round(sollMap[ort] || 0);
            const ist = round(r.ist);
            const tooltip = r.einsatzort
                ? `${r.einsatzort} hat ${soll} Soll-Stunden. ${r.name} belegt ${ist} Stunden.`
                : `Kein Einsatzort hinterlegt. ${r.name} belegt ${ist} Stunden.`;
            return { name: r.name, einsatzort: r.einsatzort, ist, soll, tooltip };
          })
          .sort((a, b) => a.name.localeCompare(b.name));
    },
    totalSoll_Dienst() {
      // SOLL ist ein Einsatzort-Budget → über eindeutige Einsatzorte summieren
      const sum = Object.values(this.sollByEinsatzort).reduce((a, b) => a + b, 0);
      return Math.round(sum * 100) / 100;
    },
    totalIst_Dienst() {
      return Math.round(this.dynamicDiensteinsatztafel.reduce((sum, r) => sum + (r.ist || 0), 0) * 100) / 100;
    },
  },
  methods: {
    async addNewElement() {
      if (this.activeCategory === 'aktivitaet') {
        this.currentActivity = {
          id: null,
          name: '',
          typ: '',
          einsatzort: '',
          verantwortliche: [], // Wichtig: Als Array initialisieren
          raeume: [],          // Wichtig: Als Array initialisieren
          termine: []          // Wichtig: Als leeres Array initialisieren
        };
        this.addTermin(); // Direkt einen leeren Termin erzeugen
        this.view = 'editor';
        await this.loadeinsatzorte();
      } else if (this.activeCategory === 'erstkraft') {
        // Initialisierung für eine neue Erstkraft
        this.currentErstkraft = {
          id: null,
          name: '',
          kuerzel: '',
          titel: '',
          farbe: getInitialColor(), // Standard-Blau oder eine andere Initialfarbe
          pflichtstunden: 26, // Optional: Standardwert setzen
          ermaessigung: 0,
          upz: 26,
          faecher: ''
        };
        this.view = 'editor';
      } else if (this.activeCategory === 'zweitkraft') {
        this.currentZweitkraft = {
          id: null,
          schuljahr_id: this.currentSchuljahrId,
          name: '',
          kuerzel: '',
          typ: 'Kinderpfleger:in', // Standard-Typ
          einsatzort: '',
          farbe: getInitialColor(),       // Ein anderes Orange zur Unterscheidung
          textfarbe: '#ffffff',
          pflichtstunden_masse: [{einsatzort: 'IB Schule', stunden: 20}],   // Beispielhafter Standardwert
          ermaessigung: 0,
          upz: 20,
          grund_ermaessigung: '',
          activeTerminIndex: null,
        };
        this.view = 'editor';
      } else if (this.activeCategory === 'raum') {
        // Initialisierung für einen neuen Raum
        this.editingRaum = {
          id: null,
          schuljahr_id: this.currentSchuljahrId,
          name: '',
          unterrichtsfach: '',
          verfuegbarkeiten: []
        };
        this.view = 'editor';
      } else if (this.activeCategory === 'schulfach') {
        this.editingFach = {
          id: null,
          schuljahr_id: this.currentSchuljahrId,
          name: '',
          farbe: getInitialColor(),
          benoetigte_raeume: [] // Wichtig: Initialisierung als leeres Array für die Checkboxen
        };
        this.view = 'editor';
      } else if (this.activeCategory === 'schuelerstundenplan') {
        this.currentSchuelerStundenPlan = {
          id: null,
          klasse_name: null,
          schuljahr_id: this.currentSchuljahrId,
          termine: []
        };
        this.view = 'editor';
      }
    },
    addNewLehrer() {
      this.personModalType = 'erstkraft'; // Wichtig für deine savePerson Logik!
      this.editingPerson = {name: '', kuerzel: '', farbe: getInitialColor()}; // Reset
      this.showPersonModal = true;
      // Wir lassen activeTerminIndex auf null, damit Fall B (Stundenplan) greift!
      this.activeTerminIndex = null;
      this.showLehrerModal = false;
    },
    addTermin() {
      if (!this.currentActivity.termine) {
        this.editingAktivitaet.termine.push({
          tag: 'Montag',
          uhrzeit: '07:45',
          endzeit: '08:30', // Standardwert für das neue Feld
          verantwortliche: [],
          raeume: []
        });
        return;
      }
      // Wir pushen ein neues Objekt in das Array
      this.currentActivity.termine.push({
        tag: 'Montag',
        uhrzeit: '07:45',
        endzeit: '08:30', // Standardwert für das neue Feld
        verantwortliche: [],
        raeume: []
      });
    },
    addZeitfenster() {
      if (!this.editingRaum.verfuegbarkeiten) {
        this.editingRaum.verfuegbarkeiten = [];
      }
      this.editingRaum.verfuegbarkeiten.push({
        tag: 'Montag',
        startzeit: '08:00',
        endzeit: '16:00'
      });
    },
    async fetchSchuljahre() {
      try {
        const response = await fetch(`${API_URL}?action=get_schuljahre`);
        this.schuljahre = await response.json();

        // Falls noch kein Jahr ausgewählt ist, nimm das erste
        if (this.schuljahre.length > 0 && !this.currentSchuljahrId) {
          this.currentSchuljahrId = this.schuljahre[0].id;
          console.log("schuljahre", this.schuljahre);

          const adressDaten = JSON.parse(this.schuljahre[0].adresse);

          // Zuweisung an dein schule-Objekt
          this.schule.adresse = {
            name: adressDaten.name || '',
            strasse: adressDaten.strasse || '',
            stadt: adressDaten.stadt || ''
          };
        }
      } catch (e) {
        console.error("Fehler beim Laden der Schuljahre:", e);
      }
    },
    lehrerMitTerminen(activeLehrerId) {
      const lehrerMap = {};

      this.erstkraefte.forEach(l => {
        lehrerMap[l.id] = {
          id: l.id,
          name: l.name,
          kuerzel: l.kuerzel,        // Neu dabei
          schuljahr_id: l.schuljahr_id, // Neu dabei
          upz: l.upz,
          pflichtstunden: l.pflichtstunden,
          ermaessigung: l.ermaessigung,
          titel: l.klassenleitung,   // Umbenennung von klassenleitung zu titel
          farbe: l.farbe,
          textfarbe: l.textfarbe,
          termine: []
        };
      });

      this.lehrerVerfuegbarkeiten.forEach(termin => {
        // 1. Prüfe direkt auf erstkraft_id (für alte Daten/Verfügbarkeiten)
        let lehrerId = termin.erstkraft_id;

        // 2. Falls nicht da, schaue in verantwortliche nach (für neue Aktivitäten)
        if (!lehrerId && termin.verantwortliche && termin.verantwortliche.length > 0) {
          // Wir nehmen die ID des ersten Verantwortlichen, wenn es eine Erstkraft ist
          const v = termin.verantwortliche[0];
          if (v.type === 'erst') {
            lehrerId = v.id;
          }
        }

        const lehrer = lehrerMap[lehrerId];
        if (lehrer) {
          lehrer.termine.push(termin);
        }
      });

      this.activeLehrerMap = lehrerMap;
      this.activeLehrer = lehrerMap[activeLehrerId];

      console.log("Lehrer mit neuer Aktivität", this.activeLehrer);
    },
    addPflichtstundenMass() {
      if (!this.currentZweitkraft) return;

      // In Vue 3 reicht eine einfache Prüfung und Zuweisung
      if (!this.currentZweitkraft.pflichtstunden_masse) {
        this.currentZweitkraft.pflichtstunden_masse = [];
      }

      // Danach ganz normal pushen
      this.currentZweitkraft.pflichtstunden_masse.push({
        einsatzort: '',
        stunden: 0
      });
    },
    addVerfuegbarkeit() {
      if (!this.editingRaum.verfuegbarkeiten) {
        this.editingRaum.verfuegbarkeiten = [];
      }
      this.editingRaum.verfuegbarkeiten.push({
        tag: 'Montag',
        startzeit: '08:00',
        endzeit: '13:00'
      });
    },
    async addYear() {
      // 1. Das höchste Jahr in der Liste finden (dient auch als Kopier-Quelle)
      let nextYearStr = "25/26"; // Standard, falls die Liste leer ist
      let quelleId = null;       // Vorjahr, aus dem kopiert werden kann
      let quelleName = '';

      if (this.schuljahre && this.schuljahre.length > 0) {
        // Wir sortieren die Jahre absteigend, um das aktuellste zu finden
        const sorted = [...this.schuljahre].sort((a, b) => b.schuljahr.localeCompare(a.schuljahr));
        const lastYear = sorted[0].schuljahr; // z.B. "24/25"
        quelleId = sorted[0].id;
        quelleName = lastYear;

        // Zerlegen ("24/25" -> ["24", "25"]) und hochrechnen
        const parts = lastYear.split('/');
        const firstPart = parseInt(parts[0]);
        if (!isNaN(firstPart)) {
          nextYearStr = `${firstPart + 1}/${firstPart + 2}`;
        }
      }

      // 2. Bestätigung (optional, aber sicher ist sicher)
      if (!await this.elliConfirm(`Soll das Schuljahr ${nextYearStr} automatisch angelegt werden?`, 'Neues Schuljahr')) return;

      // 3. Senden an die API
      try {
        const response = await fetch(`${API_URL}?action=add_schuljahr`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            // WICHTIG: Keine 'id' mitschicken! Die DB macht das selbst (auto_increment)
            schuljahr: nextYearStr,
            adresse: {name: "Neue Schule", strasse: "", stadt: ""}
          })
        });

        if (!response.ok) {
          const errorText = await response.text();
          throw new Error(errorText);
        }

        const result = await response.json();
        if (result.success) {
          const neuId = result.id;

          // 4. Optional: alle Basisdaten aus dem Vorjahr übernehmen
          if (quelleId && await this.elliConfirm(`Sollen alle Basisdaten (Erst-/Zweitkräfte, Klassen, Räume, Schulfächer, Aktivitäten und Adresse) aus dem Vorjahr ${quelleName} in das neue Schuljahr ${nextYearStr} übernommen werden?`, 'Daten übernehmen')) {
            const copyRes = await fetch(`${API_URL}?action=copy_schuljahr_data`, {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({source_schuljahr_id: quelleId, target_schuljahr_id: neuId})
            });
            const copyJson = await copyRes.json();
            if (!copyJson.success) throw new Error(copyJson.error || 'Kopieren fehlgeschlagen');
            this.showStatus(`Schuljahr ${nextYearStr} angelegt und Basisdaten aus ${quelleName} übernommen!`);
          } else {
            this.showStatus(`Schuljahr ${nextYearStr} wurde erfolgreich erstellt!`);
          }

          await this.fetchSchuljahre();            // Liste sofort aktualisieren
          if (neuId) this.currentSchuljahrId = Number(neuId); // ins neue Jahr wechseln (Watcher lädt neu)
        }
      } catch (e) {
        console.error("Fehler beim Anlegen:", e);
        this.showStatus("Fehler: " + e.message, "error");
      }
    },
    elliConfirm(message, title = 'Bestätigen') {
      return new Promise((resolve) => {
        this.dialog = {show: true, mode: 'confirm', title, message, okText: 'OK', cancelText: 'Abbrechen', _resolve: resolve};
        this.$nextTick(() => { const el = this.$refs.dialogOk; if (el && el.focus) el.focus(); });
      });
    },
    elliAlert(message, title = 'Hinweis') {
      return new Promise((resolve) => {
        this.dialog = {show: true, mode: 'alert', title, message, okText: 'OK', cancelText: 'Abbrechen', _resolve: resolve};
        this.$nextTick(() => { const el = this.$refs.dialogOk; if (el && el.focus) el.focus(); });
      });
    },
    _dialogClose(result) {
      const r = this.dialog._resolve;
      this.dialog.show = false;
      this.dialog._resolve = null;
      if (r) r(result);
    },
    async createFirstSchuljahr() {
      const jahr = (this.onboarding.schuljahr || '').trim();
      if (!jahr || this.onboardingSaving) return;
      this.onboardingSaving = true;
      try {
        const response = await fetch(`${API_URL}?action=add_schuljahr`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            schuljahr: jahr,
            adresse: {name: (this.onboarding.schulname || '').trim(), strasse: '', stadt: ''}
          })
        });
        if (!response.ok) throw new Error(await response.text());
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Unbekannter Fehler');

        await this.fetchSchuljahre();        // setzt currentSchuljahrId auf das neue Jahr
        this.showOnboardingModal = false;
        if (this.currentSchuljahrId) await this.loadFromDatabase();
        this.showStatus(`Schuljahr ${jahr} wurde angelegt!`);
      } catch (e) {
        console.error('Fehler beim Anlegen des ersten Schuljahrs:', e);
        this.showStatus('Fehler: ' + e.message, 'error');
      } finally {
        this.onboardingSaving = false;
      }
    },
    closeRaumModal() {
      this.showRaumModal = false;
      this.isQuickAddingForFach = false; // Sicherstellen, dass die Flagge gelöscht wird
    },
    calculatePercent(item) {
      if (!item.soll || item.soll === 0) {
        // Wenn kein Soll definiert ist, aber schon Stunden geplant sind: 100%
        return item.ist > 0 ? 100 : 0;
      }
      // Normalberechnung
      const percent = (item.ist / item.soll) * 100;

      // Optional: Bei 100% deckeln oder drüber hinausgehen lassen?
      // Ich empfehle max. 100 für die Breite des Balkens:
      return Math.min(percent, 100);
    },
    async confirmImport() {
      try {
        const response = await fetch(`${API_URL}?action=save_imported_data`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            schuljahr_id: this.currentSchuljahrId,
            data: this.importResults.filter(r => !r.error)
          })
        });
        const result = await response.json();
        if (result.success) {
          this.elliAlert("Daten erfolgreich übernommen.");
          this.showImportPreview = false;
          this.loadFromDatabase();
        }
      } catch (e) {
        console.error("Speicherfehler", e);
      }
    },
    async deleteElement(item) {
      if (!await this.elliConfirm(`Möchten Sie "${item.name}" wirklich löschen?`, 'Löschen bestätigen')) return;

      try {
        // Wir hängen den Typ aus activeCategory an
        const url = `${API_URL}?action=delete_element&id=${item.id}&type=${this.activeCategory}`;
        const res = await fetch(url);

        // Falls die API doch mal Text sendet, fangen wir das hier ab:
        const text = await res.text();
        let result;
        try {
          result = JSON.parse(text);
        } catch (e) {
          console.error("Server antwortete mit keinem JSON:", text);
          throw new Error("Server-Fehler (kein JSON). Details in der Konsole.");
        }

        if (result.success) {
          this.showStatus("Gelöscht", "success");
          // Daten neu laden
          if (this.activeCategory === 'schuelerstundenplan') {
            this.loadSchuelerStundenPlaene();
          } else {
            this.loadFromDatabase();
          }
        } else {
          this.elliAlert("Fehler: " + result.error);
        }
      } catch (e) {
        console.error(e);
        this.showStatus("Löschen fehlgeschlagen", "error");
      }
    },
    async editItem(item) {
      try {
        // 1. Einfache Kategorien: Daten liegen bereits lokal vor (in 'item')
        const einfacheKategorien = ['erstkraft', 'zweitkraft', 'raum', 'schulfach'];

        if (einfacheKategorien.includes(this.activeCategory)) {
          if (this.activeCategory === 'erstkraft') {
            this.currentErstkraft = {
              ...item,
              farbe: item.farbe || getInitialColor(),
              textfarbe: item.textfarbe || '#ffffff',
              pflichtstunden: Number(item.pflichtstunden) || 0,
              ermaessigung: Number(item.ermaessigung) || 0,
              upz: Number(item.upz) || 0
            };
          } else if (this.activeCategory === 'zweitkraft') {
            this.currentZweitkraft = {...item};
          } else if (this.activeCategory === 'raum') {
            console.log("raumtest", item.id);
            const response = await fetch(`${API_URL}?action=get_raum_details&id=${item.id}`);
            const data = await response.json();
            console.log("data test", data);

            this.editingRaum = {
              ...data.raum,
              // Wir stellen sicher, dass immer_verfuegbar als Boolean/Number korrekt ankommt
              immer_verfuegbar: !!parseInt(data.raum.immer_verfuegbar),
              // Hier landen die Daten aus raum_verfuegbarkeit
              verfuegbarkeiten: (data.verfuegbarkeit || []).map(v => ({
                ...v,
                startzeit: v.startzeit.substring(0, 5), // '08:00:00' -> '08:00'
                endzeit: v.endzeit.substring(0, 5)
              }))
            };
          } else if (this.activeCategory === 'schulfach') {
            this.editingFach = {
              ...item,
              // Sicherstellen, dass benoetigte_raeume ein Array ist (falls es aus der DB als String kommt)
              benoetigte_raeume: Array.isArray(item.benoetigte_raeume)
                  ? item.benoetigte_raeume
                  : (item.benoetigte_raeume ? JSON.parse(item.benoetigte_raeume) : [])
            };
          }
          this.view = 'editor';
          return; // WICHTIG: Hier stoppen, damit kein API-Call für Aktivitäten erfolgt
        }

        // 2. Komplexe Kategorie: 'aktivitaet' (benötigt Details vom Server)
        if (this.activeCategory === 'aktivitaet') {
          const response = await fetch(`${API_URL}?action=get_activity_details&id=${item.id}`);
          const result = await response.json();

          if (!result.success || !result.data) {
            this.showStatus("Fehler beim Laden: " + (result.error || "Keine Daten"), "error");
            return;
          }

          const details = result.data; // WICHTIG: result.data nutzen!
          this.view = 'editor';

          this.currentActivity = {
            id: details.id,
            name: details.name || '',
            typ: details.typ || 'AG',
            einsatzort: details.einsatzort || '',
            schuljahr_id: details.schuljahr_id,
            // Termine sauber mappen
            termine: (details.termine || []).map(t => ({
              id: t.id,
              tag: t.tag || 'Montag',
              // Sicherstellen, dass nur HH:mm im Time-Input landet
              uhrzeit: t.start ? t.start.substring(0, 5) : '07:45',
              endzeit: t.ende ? t.ende.substring(0, 5) : '08:30',
              // WICHTIG: raeume muss ein Array sein
              raeume: Array.isArray(t.raeume) ? t.raeume : (t.raum_id ? [parseInt(t.raum_id)] : []),
              // Verantwortliche mit e- und z- Präfix mappen
              verantwortliche: (t.verantwortliche || []).map(v => {
                // Die API sendet jetzt 'id' und 'type' (kraft_typ)
                const prefix = (v.type === 'erst' || v.type === 'e') ? 'e-' : 'z-';
                return prefix + v.id;
              })
            }))
          };

          if (this.currentActivity.termine.length === 0) {
            this.addTermin();
          }
          await this.loadeinsatzorte();
        } else if (this.activeCategory === 'schuelerstundenplan') {
          // 1. Daten laden
          await this.loadSchuelerStundenPlan(item.id);
          await this.loadLehrerverfuegbarkeiten();
          // 2. Erst dann View wechseln
          this.view = 'editor';
          return;
        } else if (this.activeCategory === 'raumbelegungsplan') {
          this.activeRaumId = item.id;
          await this.loadRaumVerfuegbarkeiten();
          this.view = 'editor';
        } else if (this.activeCategory === 'lehrerstundenplan') {
          await this.loadLehrerstundenplan(item.id);
          await this.loadLehrerverfuegbarkeiten();
          this.activeLehrerId = item.id;
          await this.lehrerMitTerminen(this.activeLehrerId);
          console.log("Test Lehrerstundenplan", this.activeLehrer);
          this.view = 'editor';
        } else if (this.activeCategory === 'diensteinsatzplan') {
          console.log("item-id", item.id);
          await this.loadeinsatzorte();
          this.activeZweitkraftId = item.id;
          await this.loadDiensteinsatzplan(item.id);
          this.view = 'editor';
        }
      } catch (e) {
        console.error("Detail-Load Error:", e);
        this.showStatus("Verbindung zum Server fehlgeschlagen", "error");
      }
    },
    formattedSchuelerSliderTime(val) {
      const totalMinutes = Math.round(val * 45);
      const h = Math.floor(totalMinutes / 60);
      const m = totalMinutes % 60;
      return `${h}h ${m}m`;
    },
    formattedSliderTime(val) {
      const totalMinutes = Math.round(val * 60);
      const h = Math.floor(totalMinutes / 60);
      const m = totalMinutes % 60;
      return `${h}h ${m}m`;
    },
    getIcon(category) {
      return this.categoryMap[category]?.icon || '';
    },
    getRaumNamen(termin) {
      if (!termin) return 'Wird geladen...';

      if (!termin.raeume || termin.raeume.length === 0) return 'Raum';

      return termin.raeume
          .map(id => {
            const raum = this.raeume.find(r => r.id.toString() === id.toString());
            return raum ? raum.name : '??';
          })
          .join(', ');
    },
    getVerantwortlicheNamen(termin) {
      if (!termin.verantwortliche || termin.verantwortliche.length === 0) {
        return 'Verantwortliche wählen...';
      }

      return termin.verantwortliche.map(vId => {
        // Falls die ID ein Präfix hat (z.B. "e-1")
        if (typeof vId === 'string' && vId.includes('-')) {
          const [prefix, id] = vId.split('-');
          const numericId = parseInt(id);

          if (prefix === 'e') {
            const kraft = this.erstkraefte.find(k => k.id === numericId);
            return kraft ? kraft.name : '??';
          } else if (prefix === 'z') {
            const kraft = this.zweitkraefte.find(k => k.id === numericId);
            return kraft ? kraft.name : '??';
          }
        }

        // Fallback für alte Daten ohne Präfix
        const erst = this.erstkraefte.find(k => k.id === vId);
        if (erst) return erst.name;
        const zweit = this.zweitkraefte.find(k => k.id === vId);
        if (zweit) return zweit.name;

        return '??';
      }).join(', ');
    },
    // In deinen methods:
    async handleFileUpload(event) {
      const file = event.target.files[0];
      if (!file) return;

      const formData = new FormData();
      formData.append('document', file);

      try {
        const response = await fetch(`${API_URL}?action=import_document`, {
          method: 'POST',
          body: formData
        });
        const result = await response.json();

        if (result.success) {
          // Wir speichern das Ergebnis in einem neuen Array für die Vorschau
          this.importResults = [{
            file: result.file,
            rawText: result.text,
            error: null
          }];
          this.showImportPreview = true;
        } else {
          this.elliAlert("Fehler beim Import: " + result.error);
        }
      } catch (e) {
        console.error(e);
        this.elliAlert("Upload fehlgeschlagen.");
      } finally {
        // Input zurücksetzen, damit dieselbe Datei nochmal gewählt werden kann
        event.target.value = '';
      }
    },
    h2rgb(n, h, s, l) {
      const k = (n + h / 30) % 12;
      const a = s * Math.min(l, 100 - l) / 100;
      const f = l / 100 - a / 100 * Math.max(-1, Math.min(k - 3, 9 - k, 1));
      return Math.round(255 * f).toString(16).padStart(2, '0');
    },
    updateTimeFromUnits(input = null) {
      // Nur rechnen, wenn es ein Fach (Typ 'f') ist und wir eine Startzeit haben
      if (!this.selectedUniqueKey || !this.lehrerPlanForm.start) {
        return;
      }

      // 1. Startzeit zerlegen (HH:mm)
      const [hours, minutes] = this.lehrerPlanForm.start.split(':').map(Number);

      // 2. Gesamte Minuten berechnen (Startzeit + Einheiten * 45)
      const dauerInMinuten = this.selectedUniqueKey.startsWith('f') ? (this.stundenAuswahl || input || 1) * 45 : this.tempDauer;
      let totalMinutes = hours * 60 + minutes + dauerInMinuten;

      // 3. Zurückrechnen in Stunden und Minuten (Modulo 24 für Tagesübergang)
      const endHours = Math.floor(totalMinutes / 60) % 24;
      const endMinutes = totalMinutes % 60;

      this.lehrerPlanForm.ende = `${String(endHours).padStart(2, '0')}:${String(endMinutes).padStart(2, '0')}`;
    },

    // WICHTIG: Wenn du ein Fach im Grid anklickst, musst du diese Methode ebenfalls triggern
    selectLehrerFach(item, type) {
      this.selectedUniqueKey = type + '-' + item.id;
      this.lehrerPlanForm.typ = type;
      if (type === 'a') {
        // Aktivität: aktivitaet_* setzen, Fach leeren, Einsatzort aus der Aktivität übernehmen
        this.lehrerPlanForm.aktivitaet_id = item.id;
        this.lehrerPlanForm.aktivitaet = item.name;
        this.lehrerPlanForm.fach_id = null;
        this.lehrerPlanForm.fach = null;
        this.lehrerPlanForm.einsatzort = item.einsatzort || null;
      } else {
        this.lehrerPlanForm.fach_id = item.id;
        this.lehrerPlanForm.fach = item.name;
        this.lehrerPlanForm.aktivitaet_id = null;
        this.lehrerPlanForm.aktivitaet = null;
      }
      this.updateTimeFromUnits()
    },
    selectLehrerKlasse(plan) {
      console.log("klassenwahl", this.lehrerPlanForm.klassen_id, plan.id);
      // Toggle-Logik: Wenn bereits gewählt, dann abwählen
      if (this.lehrerPlanForm.klassen_id === plan.id) {
        this.lehrerPlanForm.klassen_id = null;
        this.lehrerPlanForm.klasse = null;
      } else {
        // Neu wählen
        this.lehrerPlanForm.klassen_id = plan.id;
        this.lehrerPlanForm.klasse = plan.name;
      }
      // Wichtig: Dropdown schließen, wenn gewünscht
      this.activeDropdown = null;
    },
    // Die universelle Dropdown-Steuerung (hast du wahrscheinlich schon)
    toggleLehrerDropdown(dropdownId) {
      this.activeDropdown = this.activeDropdown === dropdownId ? null : dropdownId;
    },
    // Prüft, ob das spezifische Dropup offen ist
    isDropupOpen(id) {
      return this.openDropupId === id;
    },
    // Toggelt das Dropup
    toggleDropup(id) {
      this.openDropupId = this.openDropupId === id ? null : id;
    },
    //Selektion der Räume umschalten
    toggleRaumSelection(raumId) {
      console.log("raeume", this.raeume);
      const index = this.lehrerPlanForm.raum_ids.indexOf(raumId);
      if (index > -1) {
        this.lehrerPlanForm.raum_ids.splice(index, 1);
      } else {
        this.lehrerPlanForm.raum_ids.push(raumId);
      }
      // Falls du raeume (Objekte) synchron halten musst:
      this.lehrerPlanForm.raeume = this.raeume.filter(r =>
          this.lehrerPlanForm.raum_ids.includes(r.id)
      );
    },
    // Validierung basierend auf dem lehrerPlanForm Objekt
    isLehrerRaumVerfuegbar(form) {
      // Hier nutzt du deine bestehende Logik mit den Daten aus dem Form
      return this.isRaumVerfuegbar(form.raum_ids, form.tag, form.start, form.ende);
    },
    getLehrerRaumNamen(form) {
      if (!form.raum_ids || form.raum_ids.length === 0) return "Raum wählen...";
      return this.raeume
          .filter(r => form.raum_ids.includes(r.id))
          .map(r => r.name)
          .join(', ');
    },
    getRandomDarkColor() {
      // Hue: 0-360 (der gesamte Farbkreis)
      const h = Math.floor(Math.random() * 360);
      // Saturation: 60-80% für lebendige, aber nicht grelle Farben
      const s = Math.floor(Math.random() * 20) + 60;
      // Lightness: 35-45% sorgt für genug Kontrast zu weißer Schrift
      const l = Math.floor(Math.random() * 10) + 35;

      // Hier übergibst du h, s, l jedes Mal mit
      const r = this.h2rgb(0, h, s, l);
      const g = this.h2rgb(8, h, s, l);
      const b = this.h2rgb(4, h, s, l);

      return `#${r}${g}${b}`;
    },
    getRandomPastelColor(seedText) {
      if (!seedText) {
        // Ein dunkles, gedecktes Schieferblau als Fallback
        return 'hsl(210, 20%, 30%)';
      }

      let hash = 0;
      for (let i = 0; i < seedText.length; i++) {
        hash = ((hash << 5) + hash) + seedText.charCodeAt(i);
        hash = hash & hash;
      }

      const hue = Math.abs(hash) % 360;

      // SÄTTIGUNG: Niedriger (35% - 50%), damit sie "pastellig-gedeckt" wirken
      const saturation = 35 + (Math.abs(hash >> 8) % 15);

      // HELLIGKEIT: Niedriger (35% - 45%) für den "Dunkel-Look"
      // Bei Werten unter 50% wird dein getContrastColor-Algorithmus
      // nun automatisch wieder weißen Text wählen!
      const lightness = 35 + (Math.abs(hash >> 16) % 10);

      return `hsl(${hue}, ${saturation}%, ${lightness}%)`;
    },
    handleActionInAddToTafel() {
      this.showNewFachModal = false;

      // 2. Deine Bedingung prüfen
      if (this.returnToFachAfterAddFach) {
        this.addNewTafelItem(); // Funktion mit Klammern aufrufen
        this.returnToFachAfterAddFach = false;
      }
    },
    handleMoveStart(event, termin) {
      this.isDragging = true;
      event.dataTransfer.setData('drag-mode', 'move'); // Markierung: Wir verschieben nur
      event.dataTransfer.setData('termin-uuid', termin.uuid);
      event.target.style.opacity = "0.5";
    },
    handleDragStart(event, fach) {
      this.draggedSubject = fach;
      this.draggingId = fach.id;
      // Wir setzen 'text/plain' für maximale Browser-Kompatibilität
      event.dataTransfer.setData('fach', JSON.stringify(fach));
      event.dataTransfer.setData('text/plain', fach.id.toString());
      event.dataTransfer.effectAllowed = 'move';
    },
    handleMoveEnd(event) {
      this.isDragging = false;
      this.isOverTrash = false;
      event.target.style.opacity = "1";
    },
    handleDeleteDrop(event) {
      event.preventDefault();
      const dragMode = event.dataTransfer.getData('drag-mode');
      const uuid = event.dataTransfer.getData('termin-uuid');

      if (dragMode === 'move') {

        // Wir löschen nur, wenn es ein existierender Termin aus dem Plan ist
        if (this.activeCategory === 'lehrerstundenplan') {
          // 1. Den zu löschenden Termin finden, um seine Position (Tag/Stunde) zu kennen
          const neueListe = this.currentLehrerstundenplan.termine.filter(t => {
            return String(t.termin_id) !== uuid;
          });

          this.currentLehrerstundenplan.termine = neueListe;
          console.log("Termin wurde entfernt. Neue Anzahl:", neueListe.length);

        } else if (this.activeCategory === 'schuelerstundenplan') {
          // 1. Den zu löschenden Termin finden, um seine Position (Tag/Stunde) zu kennen
          const terminZuLoeschen = this.currentSchuelerStundenPlan.termine.find(t => t.uuid === uuid);

          if (terminZuLoeschen) {
            const {tag, stunden_id} = terminZuLoeschen;

            // 2. Den Termin aus der Liste entfernen
            this.currentSchuelerStundenPlan.termine = this.currentSchuelerStundenPlan.termine.filter(
                t => t.uuid !== uuid
            );

            // 3. CLEANUP: Prüfen, ob in diesem Slot noch genau EIN Termin übrig ist
            const verbleibendeImSlot = this.currentSchuelerStundenPlan.termine.filter(
                t => t.tag === tag && t.stunden_id === stunden_id
            );

            if (verbleibendeImSlot.length === 1) {
              // Der übrig gebliebene Termin muss nun IMMER Klassenverbund sein
              verbleibendeImSlot[0].is_differenzierung = false;

              delete verbleibendeImSlot[0].ist_differenzierung;
              delete verbleibendeImSlot[0].ist_klassenverbund;
            }
            this.currentSchuelerStundenPlan.termine = [...this.currentSchuelerStundenPlan.termine];
          }
          this.updateGridDisplay();
          this.updateIstStunden();
        }
      }

      this.isDragging = false;
      this.isOverTrash = false;
    },
    recalculateSlotStatus(tag, stundenId) {
      // Alle Termine in diesem spezifischen Slot finden
      const termineImSlot = this.currentSchuelerStundenPlan.termine.filter(
          t => t.tag === tag && t.stunden_id === stundenId
      );

      // Wenn nur noch ein Termin übrig ist, muss dieser Klassenverbund sein
      if (termineImSlot.length === 1) {
        termineImSlot[0].is_differenzierung = false;
        // Optionale Bereinigung von Alt-Attributen
        delete termineImSlot[0].ist_differenzierung;
        delete termineImSlot[0].ist_klassenverbund;
      }
      // Falls du sichergehen willst: Wenn 2 da sind, müssen beide Differenzierung sein
      else if (termineImSlot.length === 2) {
        termineImSlot.forEach(t => t.is_differenzierung = true);
      }
    },
    handleDragEnd() {
      this.draggingId = null; // Zurücksetzen
    },
    handleDrop(event, tag, stunde) {
      event.preventDefault();
      this.dragOverCell = null;

      const dragMode = event.dataTransfer.getData('drag-mode');
      const isCopy = event.ctrlKey;
      const uuid = event.dataTransfer.getData('termin-uuid');
      let item;

      // --- 1. DATEN EXTRAHIEREN ---
      if (dragMode === 'move') {
        // Wenn verschoben wird, suchen wir den Termin im aktuellen Plan
        item = this.currentSchuelerStundenPlan.termine.find(t => t.uuid === uuid);
      } else {
        // Wenn neu reingezogen, suchen wir das Fach in den verfügbaren Fächern
        const schulfachId = event.dataTransfer.getData('text/plain');
        item = this.verfuegbareFaecher.find(f => f.id == schulfachId);
      }

      if (!item) {
        console.error("Kein Item zum Droppen gefunden");
        return;
      }

      // --- 2. RÄUME ERMITTELN (Robustes Auslesen für n:m) ---
      let raumIdsToCheck = [];

      console.log("Item beim Drop:", item);

      const rawR = item.raum_ids || item.raeume || item.benoetigte_raeume || item.raum_id;
      console.log("Gefundene Roh-Raumdaten:", rawR);

      if (Array.isArray(rawR)) {
        raumIdsToCheck = rawR;
      } else if (typeof rawR === 'string') {
        if (rawR.startsWith('[')) {
          try {
            raumIdsToCheck = JSON.parse(rawR);
          } catch (e) {
            raumIdsToCheck = [];
          }
        } else if (rawR.trim() !== "") {
          raumIdsToCheck = [rawR];
        }
      } else if (rawR) {
        raumIdsToCheck = [rawR];
      }

      // Bereinigen: IDs zu Zahlen konvertieren, null/leere Werte entfernen
      raumIdsToCheck = raumIdsToCheck
          .map(id => Number(id))
          .filter(id => id > 0 && !isNaN(id));

      // --- 3. KONFLIKTPRÜFUNG (RAUM & BELEGUNG) ---
      if (raumIdsToCheck.length > 0) {

        // WICHTIG: Wir müssen sicherstellen, dass wir die ECHTE ID
        // UND die UUID ausschließen können.
        // Wenn 'item' aus 'currentSchuelerStundenPlan.termine' kommt, hat es beides.
        const excludeId = item.uuid || item.id || uuid;

        // Kleiner Debug-Tipp: Schau dir an, was hier geprüft wird
        console.log("Schuelerstundenplan Item", item);
        console.log("Prüfe Räume:", raumIdsToCheck, "für", tag, stunde.start, 'bis', stunde.ende, "Exclude:", excludeId);

        // Wir prüfen, ob JEDER Raum im Array verfügbar ist
        let alleVerfuegbar = true;

        for (const raumId of raumIdsToCheck) {
          const check = this.isRaumVerfuegbar(
              raumId,
              tag,
              stunde.start,
              stunde.ende,
              excludeId
          );

          if (check !== true) {
            alleVerfuegbar = false;
            break; // Sofort aufhören, wenn ein Raum belegt ist
          }
        }

        if (!alleVerfuegbar) {
          return; // Abbrechen
        }
      }

      // --- 4. LEHRER-PRÜFUNG ---
      if (item.erstkraft_id) {
        const excludeId = item.id || uuid;
        console.log("Input", item.erstkraft_id, tag, stunde.start, stunde.ende, excludeId);
        console.log("Lehrerverfügbarkeiten", this.lehrerVerfuegbarkeiten);
        const istLehrerFrei = this.isLehrerVerfuegbar(item.erstkraft_id, tag, stunde.start, stunde.ende, excludeId);
        if (!istLehrerFrei) {
          this.showStatus("Lehrer-Konflikt: Die Erstkraft ist bereits belegt.", "error");
          return; // DROP ABBRECHEN
        }
      }

      // --- 5. SLOT-BELEGUNG PRÜFEN (Differenzierung) ---
      const belegteSlots = this.currentSchuelerStundenPlan.termine.filter(
          t => t.tag === tag && t.stunden_id === stunde.id && t.uuid !== uuid
      ).length;

      if (belegteSlots >= 2) {
        this.showStatus("Dieser Zeitslot ist bereits voll belegt.", "error");
        return;
      }
      const wirdDifferenzierung = belegteSlots === 1;

      // --- 6. AUSFÜHRUNG ---
      if (dragMode === 'move') {
        const original = this.currentSchuelerStundenPlan.termine.find(t => t.uuid === uuid);
        if (original) {
          const oldTag = original.tag;
          const oldStundenId = original.stunden_id;

          const checkT = this.currentSchuelerStundenPlan.termine.filter(
              t => t.tag === tag && t.stunden_id === stunde.id && t.uuid !== uuid
          ).length === 0 ? 0 : 1;

          if (isCopy) {
            // --- KOPIEREN ---
            const kopie = JSON.parse(JSON.stringify(original));
            kopie.uuid = crypto.randomUUID();
            delete kopie.id; // Neue DB-ID erzwingen
            kopie.tag = tag;
            kopie.stunden_id = stunde.id;
            kopie.is_differenzierung = checkT;
            this.currentSchuelerStundenPlan.termine.push(kopie);
          } else {
            // --- VERSCHIEBEN ---
            original.tag = tag;
            original.stunden_id = stunde.id;
            original.is_differenzierung = checkT;

            // Slot-Status für alte Position aktualisieren
            if (oldTag !== tag || oldStundenId !== stunde.id) {
              this.recalculateSlotStatus(oldTag, oldStundenId);
            }
          }
        }
      } else {
        // --- NEUES FACH (Modal öffnen) ---
        this.pendingAssignment = {
          uuid: crypto.randomUUID(),
          tag: tag,
          stunden_id: stunde.id,
          schulfach_id: item.id,
          is_differenzierung: wirdDifferenzierung,
          start: stunde.start,
          ende: stunde.ende,
          raum_ids: raumIdsToCheck, // Array für n:m
          display: {
            fachName: item.name,
            farbe: item.farbe || '#444444',
            raumName: raumIdsToCheck.map(id => {
              const r = this.raeume.find(r => r.id == id);
              return r ? r.name : '';
            }).filter(n => n).join(', ')
          }
        };
        this.showLehrerModal = true;
      }

      // UI aktualisieren
      this.updateGridDisplay();
    },
    async loadeinsatzorte() {
      try {
        console.log("SchuljahrID:", this.currentSchuljahrId);
        const url = `${API_URL}?action=zweitkraft-einsatzorte&schuljahr_id=${this.currentSchuljahrId}`;
        const response = await fetch(url);

        // 1. Hole den Antwort-Text als reinen Text, nicht als JSON
        const rawData = await response.text();

        // 2. LOGGE DAS IN DIE KONSOLE!
        console.log("--- WAS DER SERVER WIRKLICH SENDE ---");
        console.log(rawData);
        console.log("-------------------------------------");

        // 3. Erst jetzt versuchen zu parsen
        const data = JSON.parse(rawData);
        this.einsatzorte = data;
        console.log('Erfolg:', this.einsatzorte);

      } catch (error) {
        console.error('Fehler beim Laden (siehe Konsole oben für die Rohdaten):', error);
      }
    },
    handleDragOver(event, tag, stunde) {
      event.preventDefault();
      this.dragOverCell = `${tag}-${stunde.id}`;

      // Wenn ALT gedrückt ist, zeige das Plus-Zeichen am Cursor
      if (event.altKey) {
        event.dataTransfer.dropEffect = 'copy';
      } else {
        event.dataTransfer.dropEffect = 'move';
      }
    },
    confirmAssignment(lehrer) {
      if (!this.pendingAssignment) return;

      const {tag, start, ende} = this.pendingAssignment;

      const lehrerOk = this.isLehrerVerfuegbar(lehrer.id, tag, start, ende);

      if (!lehrerOk) {
        this.showStatus(`Konflikt: ${lehrer.name} ist nicht verfügbar.`, "error");
        return; // Bricht ab, Modal bleibt offen
      }

      const existierendeTermineImSlot = this.currentSchuelerStundenPlan.termine.filter(t =>
          t.tag === this.pendingAssignment.tag &&
          t.stunden_id === this.pendingAssignment.stunden_id &&
          t.uuid !== this.pendingAssignment.uuid // Falls wir einen bestehenden Termin bearbeiten
      );

      const istDifferenzierung = existierendeTermineImSlot.length > 0;
      const zielFarbe = this.pendingAssignment.display.farbe || lehrer.farbe || '#e0e0e0';
      const index = this.currentSchuelerStundenPlan.termine.findIndex(
          t => t.uuid === this.pendingAssignment.uuid
      );

      const terminDaten = {
        erstkraft_id: lehrer.id,
        farbe: zielFarbe,
        // Dynamische Zuweisung basierend auf deiner neuen Regel:
        ist_klassenverbund: !istDifferenzierung,
        ist_differenzierung: istDifferenzierung,
        fachName: this.pendingAssignment.fachName,
        display: {
          ...this.pendingAssignment.display,
          lehrerKuerzel: lehrer.kuerzel,
          farbe: zielFarbe,
          typLabel: istDifferenzierung ? '(Diff)' : '(KV)'
        }
      };

      if (index !== -1) {
        // FALL: Bestehenden Termin aktualisieren
        Object.assign(this.currentSchuelerStundenPlan.termine[index], terminDaten);
      } else {
        this.currentSchuelerStundenPlan.termine.push({
          ...this.pendingAssignment,
          ...terminDaten
        });
      }

      this.updateGridDisplay();
      this.showLehrerModal = false;
      this.pendingAssignment = null;
    },
    editAssignment(termin) {
      this.pendingAssignment = {...termin};
      this.showLehrerModal = true;
      this.personModalType = 'erstkraft';
    },
    updateGridDisplay() {
      // 1. Wir leeren die aktuelle Anzeige-Map
      this.planData = {};

      // 2. Wir gehen alle Termine im Haupt-Array durch und sortieren sie neu ein
      if (this.currentSchuelerStundenPlan.termine) {
        this.currentSchuelerStundenPlan.termine.forEach(termin => {
          const cellKey = `${termin.tag}-${termin.stunden_id}`;

          if (!this.planData[cellKey]) {
            this.planData[cellKey] = [];
          }
          this.planData[cellKey].push(termin);
        });
      }
    },
    handleLehrerDragStart(event, item, type) {
      this.isDragging = true;
      this.draggingId = item.id;

      console.log("item", item);
      // WICHTIG: Die ID muss für die Vorauswahl im Modal mit!
      event.dataTransfer.setData('benoetigte_raeume', item.benoetigte_raeume || []);
      event.dataTransfer.setData('termin-uuid', item.id);
      event.dataTransfer.setData('itemType', type);
      event.dataTransfer.setData('fach-name', item.name);
      event.dataTransfer.setData('farbe', item.farbe || '#ccc');
      event.dataTransfer.setData('drag-mode', 'new'); // Markiert es als neuen Eintrag aus der Toolbox

      console.log("item.id", item.id);

      const ausgabe = {
        aktivitaet_id: type === 'a' ? item.id : null,
        aktivitaet: type === 'a' ? item.name : null,
        einsatzort: type === 'a' ? (item.einsatzort || null) : null,
        benoetigte_raeume: type === 'f' ? item.benoetigte_raeume : null,
        fach_id: type === 'f' ? item.id : null,
        fach: type === 'f' ? item.name : null,
        farbe: item.farbe,
        typ: item.typ || null
      }

      // Das komplette Objekt für komplexe Datenübernahme (optional)
      event.dataTransfer.setData('termin-full', JSON.stringify(ausgabe));

      event.dataTransfer.effectAllowed = 'copyMove';

      console.log("Drag Start Lehrerplan:", item.name, "Typ:", "ID:", item.id, "alles:", item);
    },
    handleLehrerMoveStart(event, termin) {
      this.isDragging = true;
      this.draggingId = termin.id;

      // Wir sagen dem Browser: "Das ist ein Verschiebe-Vorgang eines existierenden Termins"
      event.dataTransfer.setData('termin-full', JSON.stringify(termin));
      event.dataTransfer.setData('termin-uuid', termin.termin_id);
      event.dataTransfer.setData('drag-mode', 'move');
      event.dataTransfer.setData('fach-name', termin.aktivitaet_name); // Fachname für das Modal
      event.dataTransfer.effectAllowed = 'move';

      // Optional: CSS-Klasse für Feedback
      event.target.classList.add('is-dragging-now');
    },

    handleLehrerMoveEnd(event) {
      this.isDragging = false;
      this.isOverTrash = false;
      event.target.style.opacity = "1";
    },
    handleLehrerDrop(event, tag) {
      event.preventDefault();
      this.dragOverCell = null;
      this.isDragging = false;

      const benoetigte_raeume = event.dataTransfer.getData('benoetigte_raeume');
      const type = event.dataTransfer.getData('itemType'); // 'f' oder 'a'
      const dragMode = event.dataTransfer.getData('drag-mode');
      const item = event.dataTransfer.getData('termin-full');
      const fullData = JSON.parse(item);
      const farbe = event.dataTransfer.getData('farbe');

      console.log("benötigte Räume", fullData);

      const raeume = fullData.raum_ids ? fullData.raum_ids : benoetigte_raeume ? JSON.parse(benoetigte_raeume) : [];

      console.log("benötigte Räume 2", raeume);

      //Dauer berechnen
      if (fullData.start && fullData.ende) {
        const [h1, m1] = fullData.start.split(':').map(Number);
        const [h2, m2] = fullData.ende.split(':').map(Number);

        const startTotal = h1 * 60 + m1;
        const endeTotal = h2 * 60 + m2;

        this.tempDauer = fullData.aktivitaet_id ? endeTotal - startTotal : 45;
        this.stundenAuswahl = fullData.fach_id ? ((endeTotal - startTotal) / 45).toFixed(0) : 1;
      }

      this.lehrerPlanForm = {
        termin_id: fullData.termin_id || crypto.randomUUID(), // Erzeugt eine echte UUID,
        tag: tag,
        start: fullData.start ? fullData.start.slice(0, 5) : "08:15",
        ende: fullData.ende ? fullData.ende.slice(0, 5) : "09:00",
        klassen_name: fullData.klasse || null,
        fach: fullData.fach || null,
        fach_id: fullData.fach_id || null,
        schulfach_farbe: farbe,
        schulfach_benoetigte_raeume: benoetigte_raeume,
        stunden_id: fullData.stunden_id || null,
        is_differenzierung: fullData.is_differenzierung || null,
        aktivitaet: fullData.aktivitaet || null,
        aktivitaet_id: fullData.aktivitaet_id || null,
        klassen_id: fullData.klassen_id || null,
        einsatzort: fullData.einsatzort || null,
        raeume: fullData.raeume || [],
        raum_ids: raeume || [],
        immer_verfuegbar: fullData.immer_verfuegbar || null,
        dragMode: dragMode,
        farbe: farbe,
      };

      console.log('this.lehrerPlanForm', this.lehrerPlanForm);

      if (dragMode === 'move' && fullData) {
        //hier stand mal Oriignal
        const isCopy = event.ctrlKey;

        this.lehrerPlanForm = {
          ...this.lehrerPlanForm, // Standardwerte behalten
          tag: tag, // Neuer Tag vom Drop-Ziel
          termin_id: isCopy ? crypto.randomUUID() : this.lehrerPlanForm.termin_id,
        };
      } else {
        if (type === 'f') {
          this.updateTimeFromUnits();
        } else {
          // Standardzeit für Aktivitäten, falls gewünscht
          this.lehrerPlanForm.ende = '08:45';
        }
      }

      this.selectedUniqueKey = this.lehrerPlanForm.fach_id ? 'f-' + this.lehrerPlanForm.fach_id : 'a-' + this.lehrerPlanForm.aktivitaet_id;

      // Diensteinsatzplan bekommt sein eigenes, schlankeres Modal
      if (this.activeCategory === 'diensteinsatzplan') {
        this.showDienstPlanModal = true;
      } else {
        this.showLehrerPlanModal = true;
      }
    },
    updateEndeByMinutes(minuten) {
      this.tempDauer = minuten; // Lokal speichern für die Anzeige
      if (!this.lehrerPlanForm.start) return;

      const [h, m] = this.lehrerPlanForm.start.split(':').map(Number);
      const gesamt = h * 60 + m + parseInt(minuten || 0);

      this.lehrerPlanForm.ende =
          String(Math.floor(gesamt / 60) % 24).padStart(2, '0') + ':' +
          String(gesamt % 60).padStart(2, '0');
    },
    editLehrerAssignment(termin) {
      // 1. Tiefe Kopie des Objekts erstellen, um die Originaldaten im Plan nicht sofort zu manipulieren
      this.lehrerPlanForm = {...termin};

      // 2. Zeitformate bereinigen (Wichtig, falls aus der DB "08:00:00" kommt, brauchen wir "08:00")
      if (this.lehrerPlanForm.start) this.lehrerPlanForm.start = this.lehrerPlanForm.start.slice(0, 5);
      if (this.lehrerPlanForm.ende) this.lehrerPlanForm.ende = this.lehrerPlanForm.ende.slice(0, 5);

      this.selectedUniqueKey = this.lehrerPlanForm.aktivitaet_id ? 'a-' + this.lehrerPlanForm.aktivitaet_id :
          'f-' + this.lehrerPlanForm.fach_id;

      if (this.selectedUniqueKey.startsWith('a')) {
        //Dauer berechnen
        const [h1, m1] = this.lehrerPlanForm.start.split(':').map(Number);
        const [h2, m2] = this.lehrerPlanForm.ende.split(':').map(Number);

        const startTotal = h1 * 60 + m1;
        const endeTotal = h2 * 60 + m2;

        this.tempDauer = endeTotal - startTotal;
      } else {
        this.stundenAuswahl = 1;
      }

      this.lehrerPlanForm.dragMode = 'edit';

      // 7. Modal-Zustände vorbereiten
      this.isNewKlasse = false; // Wir bearbeiten, also standardmäßig keine neue Gruppe eingeben
      if (this.activeCategory === 'diensteinsatzplan') {
        this.showDienstPlanModal = true;
      } else {
        this.showLehrerPlanModal = true;
      }

      console.log("raum", this.raeume);
    },
    async saveLehrerTermin() {
      // 1. Basis-Validierung
      if (!this.lehrerPlanForm.start || !this.lehrerPlanForm.ende) {
        this.showStatus("Bitte Start- und Endzeit angeben", 'error');
        return;
      }

      let startNeu = this.lehrerPlanForm.start;
      let endeNeu = this.lehrerPlanForm.ende;
      const tagNeu = this.lehrerPlanForm.tag;

      for (const eintrag of this.lehrerPlanForm.raum_ids) {
        const verfuegbar = this.isRaumVerfuegbar(
            eintrag,
            this.lehrerPlanForm.tag,
            this.lehrerPlanForm.start,
            this.lehrerPlanForm.ende,
            this.lehrerPlanForm.termin_id
        );

        if (!verfuegbar) {
          return; // Diese Zeile beendet jetzt die gesamte Methode!
        }
      }

      const excludeId = this.lehrerPlanForm.termin_id;

      // Ziel-Plan bestimmen: Diensteinsatzplan (Zweitkraft) oder Lehrerstundenplan (Erstkraft)
      const isDienst = this.activeCategory === 'diensteinsatzplan';
      const targetPlan = isDienst ? this.currentDiensteinsatzplan : this.currentLehrerstundenplan;
      if (!targetPlan.termine) targetPlan.termine = [];

      // --- B. KLASSEN-VERFÜGBARKEITSPRÜFUNG ---
      // Prüft Zeitraster [cite: 6] und bestehende Termine der Klasse
      if (this.lehrerPlanForm.klassen_id) {
        const klasseCheck = this.isKlasseVerfuegbar(
            this.lehrerPlanForm.klassen_id,
            tagNeu,
            startNeu,
            endeNeu,
            excludeId
        );
        if (!klasseCheck) return; // Bricht ab, falls Klasse nicht verfügbar oder außerhalb Raster
      }


      // --- B. TERMINKONFLIKTPRÜFUNG (Doppelbelegung der Kraft) ---
      // Prüft, ob die aktuelle Erst-/Zweitkraft zur selben Zeit schon verplant ist.

      const kraftDaten = isDienst
          ? this.currentDiensteinsatzplan
          : (this.currentLehrerstundenplan[this.activeLehrer.id] || this.currentLehrerstundenplan);

      if (kraftDaten && kraftDaten.termine) {
        const doppelung = kraftDaten.termine.find(t => {
          // 1. Nicht mit sich selbst vergleichen (wichtig beim Bearbeiten)
          // PHP liefert 'termin_id'
          if (excludeId && t.termin_id === excludeId) return false;

          // 2. Nur Termine am gleichen Tag prüfen
          if (t.tag !== tagNeu) return false;

          // 3. Überschneidungs-Check: (StartA < EndeB) UND (EndeA > StartB)
          // Die Zeiten kommen als "HH:mm:ss" aus der DB [cite: 12, 14]
          console.log("Test Überschneidung", startNeu, t.ende, endeNeu, t.start);
          return startNeu.slice(0, 5) < t.ende.slice(0, 5) && endeNeu.slice(0, 5) > t.start.slice(0, 5);
        });

        if (doppelung) {
          // Bezeichnung ermitteln (Fach oder Aktivität)
          const info = doppelung.aktivitaet || doppelung.fach || 'anderer Termin';
          const zeit = `${doppelung.start.substring(0, 5)} - ${doppelung.ende.substring(0, 5)}`;
          const kraftName = isDienst
              ? (this.zweitkraefte.find(z => z.id === this.activeZweitkraftId)?.name || 'Die Kraft')
              : (this.erstkraefte.find(r => r.id === this.activeLehrerId)?.name || 'Die Lehrkraft');

          this.showStatus(
              `${kraftName} ist bereits verplant mit "${info}" (${zeit} Uhr)`,
              "error"
          );
          return; // Abbruch der Speicherung
        }
      }

      // --- C. DATEN-MANIPULATION (Das Herzstück für die UI) ---
      // 1. Altes Objekt entfernen (beim Verschieben)
      if (this.lehrerPlanForm.dragMode === 'move' || this.lehrerPlanForm.dragMode === 'edit') {
        targetPlan.termine = targetPlan.termine.filter(t => {
          // Wandle beides in Strings um, um "42" === 42 Probleme zu vermeiden
          const matchUuid = t.termin_id === this.lehrerPlanForm.termin_id;
          // Gib true zurück für alles, was NICHT gelöscht werden soll
          return !(matchUuid);
        });
      }

      // Kraft-spezifische Zusatzfelder. Der Einsatzort haengt an der Aktivität
      // (aktivitaet.einsatzort) und wird von dort abgeleitet – nicht frei gesetzt.
      const gewaehlteAktivitaet = (this.aktivitaeten || []).find(a => a.id === this.lehrerPlanForm.aktivitaet_id);
      const kraftFelder = isDienst
          ? {
              zweitkraft_id: this.activeZweitkraftId,
              // Einsatzort ist fest an der Aktivität (nur zur Anzeige mitgeführt)
              einsatzort: gewaehlteAktivitaet?.einsatzort || this.lehrerPlanForm.einsatzort || null
            }
          : { erstkraft_id: this.activeLehrerId };

      let neuerTermin = {
        ...this.lehrerPlanForm,
        ...kraftFelder,
        tag: tagNeu,
        start: startNeu.length === 5 ? startNeu + ":00" : startNeu,
        ende: endeNeu.length === 5 ? endeNeu + ":00" : endeNeu,
        termin_id: crypto.randomUUID()
      };

      // 2. Neues Objekt bauen
      if (this.lehrerPlanForm.aktivitaet) {
        targetPlan.termine.push(neuerTermin);
      } else {
        for (let i = 1; i <= this.stundenAuswahl; i++) {
          // 1. Startzeit zerlegen (HH:mm)
          const [hours, minutes] = startNeu.split(':').map(Number);
          // 2. Gesamte Minuten berechnen (Startzeit + Einheiten * 45)
          let totalMinutes = hours * 60 + minutes + 45;
          // 3. Zurückrechnen in Stunden und Minuten (Modulo 24 für Tagesübergang)
          const endHours = Math.floor(totalMinutes / 60) % 24;
          const endMinutes = totalMinutes % 60;
          endeNeu = `${String(endHours).padStart(2, '0')}:${String(endMinutes).padStart(2, '0')}`;

          neuerTermin = {
            ...this.lehrerPlanForm,
            ...kraftFelder,
            start: startNeu.length === 5 ? startNeu + ":00" : startNeu,
            ende: endeNeu.length === 5 ? endeNeu + ":00" : endeNeu,
            termin_id: crypto.randomUUID()
          }
          targetPlan.termine.push(neuerTermin);

          startNeu = `${String(endHours).padStart(2, '0')}:${String(endMinutes).padStart(2, '0')}`;
        }
      }

      if (this.lehrerPlanForm.klasse && this.isNewKlasse) {
        await this.saveKlasse();
        await this.loadSchuelerStundenPlaene();
      }

      console.log("Lehrerstundenplan", this.currentLehrerstundenplan);
      this.resetLehrerForm();
      this.showLehrerPlanModal = false;
      this.showDienstPlanModal = false;
      this.showStatus("Termin verarbeitet", "success");
    },
    async saveKlasse() {
      try {
        const response = await fetch(`${API_URL}?action=save_klasse`, {
          method: 'POST',
          body: JSON.stringify({
            klasse: this.lehrerPlanForm.klasse,
            schuljahr_id: this.currentSchuljahrId
          })
        });

        const result = await response.json();

        if (result.success) {
          console.log('Gespeichert!');
          // Hier könntest du die Liste neu laden oder ein "Success-Glow" triggern
        } else {
          // Hier fängst du die Meldung "Klasse existiert bereits" ab
          console.error('Fehler: ' + result.error);
        }
      } catch (error) {
        console.error('Netzwerkfehler oder Server down:', error);
      }
    },
    resetLehrerForm() {
      // 1. Die Daten zurücksetzen
      this.lehrerPlanForm = {
        klassen_id: null,
        newKlassenName: '',
        start: '08:00',
        ende: '09:30',
        tag: '',
        is_differenzierung: false,
        draggedItem: null,
        itemType: '',
        typ: 'a',       // Wichtig: Auch den Typ zurücksetzen
      };
      this.isNewKlasse = false;
      this.selectedUniqueKey = null; // Visuelle Auswahl im Grid löschen
      this.stundenAuswahl = 1;

      // 2. DAS MODAL SCHLIESSEN
      this.showLehrerPlanModal = false;
      this.showDienstPlanModal = false;
    },
    async saveLehrerStundenplan() {
      this.currentLehrerstundenplan = {
        ...this.currentLehrerstundenplan,
        schuljahr_id: this.currentSchuljahrId,
        erstkraft_id: this.erstkraefte.find(r => r.name === this.currentLehrerstundenplan.name)?.id
      };
      console.log("erstkräfte", this.currentLehrerstundenplan);
      try {
        const response = await fetch(`${API_URL}?action=save_lehrerstundenplan`, {
          method: 'POST',
          body: JSON.stringify(this.currentLehrerstundenplan)
        });

        const result = await response.json();

        if (result.success) {
          this.showStatus("Der Lehrerstundenplan wurde erfolgreich gespeichert.");
          // Hier könntest du die Liste neu laden oder ein "Success-Glow" triggern
        } else {
          // Konflikte (Raum/Kraft/Klasse) vom Server sichtbar machen
          this.showStatus(result.error || "Speichern fehlgeschlagen", "error");
          console.error('Fehler: ' + result.error);
        }
      } catch (error) {
        console.error('Netzwerkfehler oder Server down:', error);
      }
    },
    async saveDiensteinsatzplan() {
      // Speichert die Termine der Zweitkraft. WICHTIG: currentDiensteinsatzplan NICHT
      // überschreiben – es hält die geladenen SOLL-/IST-Daten für die Ansicht.
      const payload = {
        ...this.currentDiensteinsatzplan,
        schuljahr_id: this.currentSchuljahrId,
        zweitkraft_id: this.activeZweitkraftId
      };
      try {
        const response = await fetch(`${API_URL}?action=save_diensteinsatzplan`, {
          method: 'POST',
          body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (result.success) {
          this.showStatus("Der Diensteinsatzplan wurde erfolgreich gespeichert.");
          // Neu laden, damit neue Termine ihre echten DB-IDs erhalten (statt temp-UUIDs)
          await this.loadDiensteinsatzplan(this.activeZweitkraftId);
        } else {
          console.error('Fehler: ' + result.error);
          this.showStatus(result.error || "Speichern fehlgeschlagen", "error");
        }
      } catch (error) {
        console.error('Netzwerkfehler oder Server down:', error);
        this.showStatus("Verbindung zum Server fehlgeschlagen", "error");
      }
    },
    exportDiensteinsatzplan() {
      // Lädt den Diensteinsatzplan als Word-Datei herunter (Server generiert .docx)
      if (!this.activeZweitkraftId || !this.currentSchuljahrId) {
        this.showStatus("Keine Zweitkraft ausgewählt", "error");
        return;
      }
      const url = `${API_URL}?action=export_diensteinsatzplan&zweitkraft_id=${this.activeZweitkraftId}&schuljahr_id=${this.currentSchuljahrId}`;
      window.open(url, '_blank');
    },
    exportLehrerstundenplan() {
      // Lädt den Lehrerstundenplan als Word-Datei herunter (Server generiert .docx)
      if (!this.activeLehrerId || !this.currentSchuljahrId) {
        this.showStatus("Keine Lehrkraft ausgewählt", "error");
        return;
      }
      const url = `${API_URL}?action=export_lehrerstundenplan&erstkraft_id=${this.activeLehrerId}&schuljahr_id=${this.currentSchuljahrId}`;
      window.open(url, '_blank');
    },
    exportSchuelerstundenplan() {
      // Lädt den Schülerstundenplan (Klasse) als Word-Datei herunter
      const klasseId = this.currentSchuelerStundenPlan?.id;
      if (!klasseId) {
        this.showStatus("Bitte den Plan zuerst speichern", "error");
        return;
      }
      const url = `${API_URL}?action=export_schuelerstundenplan&klasseId=${klasseId}`;
      window.open(url, '_blank');
    },
    exportRaumbelegungsplan() {
      // Lädt den Raumbelegungsplan als Word-Datei herunter
      if (!this.activeRaumId) {
        this.showStatus("Kein Raum ausgewählt", "error");
        return;
      }
      const url = `${API_URL}?action=export_raumbelegungsplan&raumId=${this.activeRaumId}`;
      window.open(url, '_blank');
    },
    cancelAssignment() {
      this.showLehrerModal = false;
      this.showPersonModal = false;
      this.pendingAssignment = null; // Daten einfach löschen
    },
    getAssignment(tag, stunde) {
      const cellKey = `${tag}-${stunde.id}`;
      return this.planData[cellKey] || null;
    },
    openNewFachModalFromTafel() {
      this.showTafelSelectionModal = false;
      this.openQuickAdd('fach'); // Dein existierendes Modal zum Neuanlegen
      this.returnToFachAfterAddFach = true;
    },
    editTafelEintrag(item) {
      // 1. Grunddaten laden
      this.newTafelEntry = {
        ...item
      };

      if (item.fach_id) {
        this.selectedType = 'f'; // Slider anzeigen
        this.selectedUniqueKey = 'f-' + item.fach_id;
        this.selectedFachId = item.fach_id;
      } else if (item.aktivitaet_id) {
        this.selectedType = 'a'; // Zeitfelder anzeigen
        this.selectedUniqueKey = 'a-' + item.aktivitaet_id;
        this.selectedFachId = item.aktivitaet_id;
      }

      this.einheiten_kv = item.soll_klassenverbund * 60 / 45;
      this.einheiten_diff = item.soll_differenzierung * 60 / 45;

      // 3. Zeiten für die Eingabefelder vorbereiten (falls Lehrerplan)

      this.newTafelEntry.soll_stunden_klassenverbund = Math.floor(item.soll_klassenverbund).toFixed(0) || 0;
      this.newTafelEntry.soll_minuten_klassenverbund = ((item.soll_klassenverbund * 60) % 60).toFixed(0);

      this.newTafelEntry.soll_stunden_differenzierung = Math.floor(item.soll_differenzierung).toFixed(0) || 0;
      this.newTafelEntry.soll_minuten_differenzierung = ((item.soll_differenzierung * 60) % 60).toFixed(0);


      this.showTafelSelectionModal = true;
    },
    saveFachToStundentafel() {
      if (!this.selectedFachId) return;

      let index = null;
      let daten = {};

      const sollKlasse = this.selectedUniqueKey.startsWith('f') || this.activeCategory === 'schuelerstundenplan' ? (Number(Number(this.newTafelEntry.soll_klassenverbund).toFixed(2)) || 0) :
          (parseInt(this.newTafelEntry.soll_stunden_klassenverbund || 0) + (parseInt(this.newTafelEntry.soll_minuten_klassenverbund || 0) / 60)).toFixed(2) || 0;
      const sollDiff = this.selectedUniqueKey.startsWith('f') || this.activeCategory === 'schuelerstundenplan' ? (Number(Number(this.newTafelEntry.soll_differenzierung).toFixed(2)) || 0) :
          (parseInt(this.newTafelEntry.soll_stunden_differenzierung || 0) + (parseInt(this.newTafelEntry.soll_minuten_differenzierung || 0) / 60)).toFixed(2) || 0;

      // Prüfen, ob dieses Fach bereits in der Stundentafel existiert
      if (this.activeCategory === 'schuelerstundenplan') {
        index = this.stundentafel.findIndex(s => s.name === this.newTafelEntry.name);
      }

      daten = {
        fach_id: this.activeCategory === 'schuelerstundenplan' || this.selectedUniqueKey.startsWith('f') ? this.selectedFachId : null,
        name: this.newTafelEntry.name,
        farbe: this.newTafelEntry.farbe,
        soll_klassenverbund: sollKlasse,
        soll_differenzierung: sollDiff,
        soll: (sollKlasse + sollDiff)
      };

      if (this.activeCategory === 'schuelerstundenplan') {
        index = this.stundentafel.findIndex(s => s.name === this.newTafelEntry.name);
      } else if (this.activeCategory === 'lehrerstundenplan') {
        index = this.currentLehrerstundenplan.lehrer_stundentafel.findIndex(s => s.bezeichnung === this.newTafelEntry.name);
        daten = {
          ...daten,
          aktivitaet_id: this.selectedUniqueKey.startsWith('a') ? this.selectedFachId : null,
          bezeichnung: this.newTafelEntry.name
        };
      }

      if (this.activeCategory === 'lehrerstundenplan') {
        // 1. Wir holen uns die aktuellen IST-Werte für genau dieses Fach aus deiner berechneten Liste
        const currentName = this.newTafelEntry.name;
        const stats = this.dynamicLehrerstundentafel.find(s => s.name === currentName);

        // 2. Werte sicher als Zahlen extrahieren (verhindert String-Probleme)
        const sollKV = Number(daten.soll_klassenverbund || 0);
        const sollD = Number(daten.soll_differenzierung || 0);
        const istKV = stats ? Number(stats.ist_klassenverbund || 0) : 0;
        const istDiff = stats ? Number(stats.ist_differenzierung || 0) : 0;

        // 3. Die "Alles-auf-Null"-Prüfung
        if (sollKV === 0 && sollD === 0 && istKV === 0 && istDiff === 0) {

          // 4. Jetzt suchen wir den ECHTEN Index in der Original-Quelle
          const originalIndex = this.currentLehrerstundenplan.lehrer_stundentafel.findIndex(
              item => item.bezeichnung === currentName
          );

          if (originalIndex !== -1) {
            // Gefunden! Wir löschen ihn aus dem Original-Array
            this.currentLehrerstundenplan.lehrer_stundentafel.splice(originalIndex, 1);
            console.log("Eintrag gelöscht, da keine Stunden mehr vorhanden sind.");
          }

          // Modal schließen
          this.resetModal();
          this.showTafelSelectionModal = false;
          return;
        }
      }

      if (index !== -1) {
        // FALL A: Fach existiert schon -> Werte überschreiben (Reaktivität beachten!)
        if (this.activeCategory === 'schuelerstundenplan') {
          this.stundentafel[index] = {...this.stundentafel[index], ...daten};
        } else if (this.activeCategory === 'lehrerstundenplan') {
          this.currentLehrerstundenplan.lehrer_stundentafel[index] = {...this.currentLehrerstundenplan.lehrer_stundentafel[index], ...daten};
        }
      } else if (this.activeCategory === 'schuelerstundenplan') {
        // FALL B: Fach ist neu -> Hinzufügen
        this.stundentafel.push({
          id: Date.now(),
          ...daten
        });
      } else if (this.activeCategory === 'lehrerstundenplan') {
        this.currentLehrerstundenplan.lehrer_stundentafel.push({
          id: Date.now(),
          ...daten
        });
      }

      this.resetModal(); // Modal schließen & Variablen resetten
      this.showTafelSelectionModal = false;
    },
    resetModal() {
      this.showTafelSelectionModal = false;
      this.selectedFachId = null;
      this.sliderSteps = 0;              // Reset Slider 1
      this.sliderSteps_differenzierung = 0; // Reset Slider 2
      this.tempHours = null;
      this.tempMinutes = null;
      this.newTafelEntry = {};
      this.selectedUniqueKey = null;
    },
    async saveSchuelerStundenplan() {
      // 1. Validierung: Klassenname prüfen
      if (!this.currentSchuelerStundenPlan.klasse_name || this.currentSchuelerStundenPlan.klasse_name.trim() === "") {
        this.showStatus("Bitte geben Sie eine Klassenbezeichnung ein.", "error");
        return;
      }

      const stundentafelArray = [];

      if (this.stundentafel && Array.isArray(this.stundentafel)) {
        this.stundentafel.forEach(eintrag => {
          // 1. Wir suchen die ID. Wir prüfen alle Varianten:
          // Zuerst 'schulfach_id' (vom Modal), dann 'fach_id' (aus DB), dann 'id' (als Fallback)
          let fId = eintrag.schulfach_id || eintrag.fach_id || eintrag.id;

          // 2. Sicherheitscheck: Wenn die ID eine temporäre Frontend-ID ist (z.B. Date.now())
          // und wir wissen, dass echte Fach-IDs in der DB klein sind, müssen wir
          // sicherstellen, dass wir nicht versehentlich eine Riesen-ID als fach_id senden.
          if (fId > 2000000000 && !eintrag.schulfach_id) {
            fId = null;
          }

          if (fId || eintrag.name) {
            stundentafelArray.push({
              schulfach_id: fId,
              verbund: parseFloat(eintrag.soll_klassenverbund) || 0,
              diff: parseFloat(eintrag.soll_differenzierung) || 0,
              name: eintrag.name // Name mitschicken hilft beim Debuggen
            });
          }
        });
      }

      // Falls das immer noch undefined ist, nimm diesen Weg:
      const meinRasterAlternative = this.$.exposed.zeitRaster.value;

      try {
        // 3. Request an die API senden
        const response = await fetch(`${API_URL}?action=save_schuelerstundenplan`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            plan: this.currentSchuelerStundenPlan,
            klassenName: this.currentSchuelerStundenPlan.klasse_name,
            stundentafel: stundentafelArray,
            schuljahrId: this.currentSchuljahrId,
            zeitRaster: meinRasterAlternative,
          })
        });

        const result = await response.json();

        if (result.success) {
          // Neue ID für nachfolgende Updates setzen
          this.currentSchuelerStundenPlan.id = result.klasseId;
          this.showStatus("Schülerstundenplan erfolgreich gespeichert!", "success");
          await this.loadSchuelerStundenPlaene();

          // Optional: Liste der Pläne im Hintergrund aktualisieren
          //if (typeof this.loadKlassen === 'function') {
          //  this.loadKlassen();
          //}
        } else {
          throw new Error(result.error);
        }
      } catch (error) {
        console.error("Fehler beim Speichern:", error);
        this.showStatus("Speicherfehler: " + error.message, "error");
      }
      // DEBUGGING
      console.log("--- SENDING DATA TO API ---");
      console.log("KlassenName:", this.currentSchuelerStundenPlan.klasse_name);
      console.log("SchuljahrId:", this.currentSchuljahrId);
      console.table(stundentafelArray); // Zeigt das Array als schicke Tabelle in der Konsole
      console.log("Gesamter Plan:", this.currentSchuelerStundenPlan);
    },
    updateIstStunden() {
      // Wir gehen die vorhandene Liste durch und updaten nur den 'ist' Wert
      this.dynamicStundentafel.forEach(fach => {
        // Zähle Termine im Plan, die diesem Fach entsprechen
        const anzahl = this.currentSchuelerStundenPlan.termine.filter(t =>
            t.schulfach_id === fach.id || t.fach === fach.name
        ).length;

        fach.ist = anzahl;
      });
    },
    // Diese Methode hilft uns im Template zu prüfen, ob etwas offen ist
    isDropdownOpen(id) {
      return this.activeDropdown === id;
    },
    isRaumVerfuegbar(raum_id, tag, start, ende, termin_id = null) {
      // 1. Den richtigen Raum aus dem Array finden.
      //    Vergleich per String, da IDs je nach Quelle als Zahl (parseInt beim
      //    Neuanlegen) oder String (PDO liefert Spalten als String) vorliegen.
      const raum = this.raumVerfuegbarkeiten.find(r => String(r.id) === String(raum_id));

      if (!raum) return false;

      // Regel: Ohne hinterlegte Zeitfenster gilt ein Raum als durchgängig verfügbar
      // (Mo–Fr). Nur wenn Fenster existieren, ist er ausschließlich in diesen frei.
      if (raum.immer_verfuegbar !== 1 && raum.verfuegbarkeiten && raum.verfuegbarkeiten.length > 0) {
        // Prüfen, ob der Zeitraum in eines der erlaubten Fenster passt
        const hatSlot = raum.verfuegbarkeiten.some(v => {
          return v.tag === tag &&
              start >= v.start &&
              ende <= v.ende;
        });

        if (!hatSlot) {
          const zeiten = raum.verfuegbarkeiten
              .map(v => `${v.tag} ${v.start.slice(0, 5)}–${v.ende.slice(0, 5)}`)
              .join(', ');
          this.showStatus(`${raum.name} ist zu dieser Zeit nicht verfügbar. Verfügbar: ${zeiten}.`, "error");
          return false;
        }
      }

      // 3. Kollisionsprüfung mit existieFrenden Terminen
      const hatKollision = raum.termine.find(t => {
        // Falls wir einen bestehenden Termin bearbeiten, ignorieren wir ihn selbst bei der Prüfung
        if (t.termin_id === termin_id) return false;

        // Logik für Zeitüberschneidung:
        // Ein Termin kollidiert, wenn er am gleichen Tag ist UND
        // (Start_neu < Ende_alt) UND (Ende_neu > Start_alt)
        return t.tag === tag &&
            start < t.ende &&
            ende > t.start;
      });

      if (hatKollision) {
        const aktivitaet = hatKollision.aktivitaet_id ? this.aktivitaeten.find(a => a.id === hatKollision.aktivitaet_id) :
            this.faecher.find(a => a.id === hatKollision.fach_id);
        const name = aktivitaet ? aktivitaet.name : "Unbekannte Aktivität";
        this.showStatus(`${raum.name} belegt durch "${name}" (${hatKollision.start.slice(0, 5)} - ${hatKollision.ende.slice(0, 5)} Uhr)`, "error");
        return false;
      }
      return true; // Alles okay!
    },
    isKlasseVerfuegbar(klassenId, tag, start, ende, ignoreTerminId = null) {
      const klasse = this.klassenVerfuegbarkeiten.find(k => k.id === klassenId);

      if (!klasse) {
        console.error("Klasse nicht gefunden");
        return false;
      }

      // --- CHECK 1: ZEITRASTER (Abdeckung über mehrere Stunden) ---
      // Wir filtern alle Rasterstunden der Klasse, die innerhalb unseres Wunschzeitraums liegen
      const passendeRasterStunden = klasse.verfuegbarkeiten.filter(z => {
        const rStart = z.startzeit.slice(0, 5);
        const rEnde = z.endzeit.slice(0, 5);
        // Eine Rasterstunde ist Teil unseres Blocks, wenn sie sich damit überschneidet
        return rStart < ende && rEnde > start;
      });

      // Validierung der Abdeckung:
      if (passendeRasterStunden.length === 0) {
        this.showStatus(`Zeitraum passt in keine Rasterstunde für Klasse ${klasse.name}.`, "error");
        return false;
      }

      // Prüfen, ob die gefundenen Rasterstunden den Zeitraum lückenlos füllen
      const minStart = passendeRasterStunden.reduce((min, p) => p.startzeit < min ? p.startzeit : min, "23:59").slice(0, 5);
      const maxEnde = passendeRasterStunden.reduce((max, p) => p.endzeit > max ? p.endzeit : max, "00:00").slice(0, 5);

      // Der Block muss genau am Anfang der ersten Rasterstunde beginnen
      // und am Ende der letzten Rasterstunde enden.
      if (minStart > start || maxEnde < ende) {
        this.showStatus(`Der Zeitraum überschreitet das Raster oder ist unvollständig (Start: ${minStart}, Ende: ${maxEnde}).`, "error");
        return false;
      }

      // --- CHECK 2: BELEGUNG (Kollision mit anderen Terminen) ---
      const kollision = klasse.termine.find(t => {
        if (ignoreTerminId && t.termin_id === ignoreTerminId) return false;
        if (t.tag !== tag) return false;

        // Standard Überschneidungs-Check
        const tStart = t.start.slice(0, 5);
        const tEnde = t.ende.slice(0, 5);
        return start < tEnde && ende > tStart;
      });

      if (kollision) {
        const fStart = kollision.start.substring(0, 5);
        const fEnde = kollision.ende.substring(0, 5);
        this.showStatus(`Klasse ${klasse.name} ist bereits belegt: "${kollision.fach}" (${fStart}-${fEnde}).`, "error");
        return false;
      }

      return true;
    },
    isLehrerVerfuegbar(lehrerId, tag, start, ende, ignoreUuid = null) {
      console.log("isLehrerVerfuegbar Parameter", lehrerId, tag, start, ende, ignoreUuid);
      console.log("isLehrerVerfuegbar LehrerVerfügbarkeiten", this.lehrerVerfuegbarkeiten);
      // 1. Den richtigen Lehrer aus der Liste suchen
      const lehrer = this.lehrerVerfuegbarkeiten.find(l => l.id === lehrerId);

      // Wenn der Lehrer nicht existiert oder keine Termine hat, ist er verfügbar
      if (!lehrer || !lehrer.termine) {
        return true;
      }

      // 2. Prüfen, ob irgendein Termin mit dem gewünschten Zeitraum kollidiert
      // .some() gibt true zurück, sobald eine Kollision gefunden wird
      const hatKollision = lehrer.termine.some(termin => {
        // Falls wir einen bestimmten Termin ignorieren wollen (beim Bearbeiten)
        if (ignoreUuid && termin.termin_id === ignoreUuid) {
          return false;
        }

        // Nur Termine am gleichen Tag prüfen
        if (termin.tag !== tag) {
          return false;
        }

        // Logik für Zeitüberschneidung:
        // Ein Termin kollidiert, wenn: (StartA < EndeB) UND (EndeA > StartB)
        const terminStart = termin.start;
        const terminEnde = termin.ende;

        return start < terminEnde && ende > terminStart;
      });

      // Wenn keine Kollision gefunden wurde, ist der Lehrer verfügbar
      return !hatKollision;
    },
    async loadKlassenVerfuegbarkeiten() {
      try {
        // Annahme: schuljahr_id ist in der Komponente oder einem Store gespeichert
        const sid = this.currentSchuljahrId;

        if (!sid) {
          console.warn("Keine Schuljahr-ID gefunden.");
          return;
        }

        const response = await fetch(`${API_URL}?action=get_klassen_verfuegbarkeit&schuljahr_id=${sid}`);

        if (!response.ok) {
          throw new Error(`Server-Fehler: ${response.status}`);
        }

        const data = await response.json();

        // Wir speichern die Daten in einer Property der Komponente
        // Diese wird dann von isKlasseVerfuegbar() durchsucht
        this.klassenVerfuegbarkeiten = data;

        console.log("Klassen-Verfügbarkeiten erfolgreich geladen", this.klassenVerfuegbarkeiten);
      } catch (error) {
        console.error("Fehler beim Laden der Klassen-Verfügbarkeiten:", error);
        this.showStatus("Fehler beim Laden der Klassendaten", "error");
      }
    },
    async loadLehrerstundenplan(lehrerId) {
      if (!lehrerId || !this.currentSchuljahrId) return;

      try {
        const response = await fetch(`${API_URL}?action=get_lehrerstundenplan&erstkraft_id=${lehrerId}&schuljahr_id=${this.currentSchuljahrId}`);
        const data = await response.json();

        if (data && !data.error) {
          this.currentLehrerstundenplan = {
            ...data
          };
        } else {
          this.showStatus(data.error || "Fehler beim Laden", "error");
        }
      } catch (error) {
        console.error("Fetch-Fehler:", error);
      }
    },
    // Hilfsfunktion für den Lehrer (analog zum Raum)
    async loadLehrerverfuegbarkeiten() {
      if (!this.currentSchuljahrId) return;
      try {
        const res = await fetch(`${API_URL}?action=get_lehrerverfuegbarkeiten&schuljahr_id=${this.currentSchuljahrId}`);
        const result = await res.json();
        if (result.success && result.data) {
          this.lehrerVerfuegbarkeiten = result.data;
          await this.loadRaumVerfuegbarkeiten();
        }
      } catch (e) {
        console.error("Lehrerverfügbarkeiten konnten nicht geladen werden", e);
      }
    },
    async loadRaeume() {
      if (!this.currentSchuljahrId) {
        console.warn("Abbruch: Keine Schuljahr-ID vorhanden.");
        return;
      }

      try {
        const url = `${API_URL}?action=load_raeume&schuljahr_id=${this.currentSchuljahrId}`;
        const response = await fetch(url);

        if (!response.ok) throw new Error(`HTTP-Fehler! Status: ${response.status}`);

        const resData = await response.json();

        // Falls deine API die Daten in einem Unterfeld wie 'data' liefert:
        const dataArray = Array.isArray(resData) ? resData : resData.data;

        if (Array.isArray(dataArray)) {
          this.raeume = dataArray;
          console.log("Räume erfolgreich geladen:", this.raeume);
        } else {
          console.error("API-Struktur ungültig. Erwartete Array, erhielt:", resData);
        }
      } catch (e) {
        console.error("Kritischer Fehler beim Laden der Räume:", e);
      }
    },
    async loadRaumVerfuegbarkeiten() {
      if (!this.currentSchuljahrId) return;
      const response = await fetch(`${API_URL}?action=get_raum_verfuegbarkeit&schuljahr_id=${this.currentSchuljahrId}`);
      try {
        const data = await response.json();

        if (data && !data.error) {
          this.raumVerfuegbarkeiten = data;
        } else {
          this.showStatus(data.error || "Fehler beim Laden", "error");
        }
      } catch (error) {
        console.error("Fetch-Fehler:", error);
      }
    },
    timeToMinutes(timeString) {
      if (!timeString) return 0;
      const [hours, minutes] = timeString.split(':').map(Number);
      return hours * 60 + minutes;
    },
    getTerminStyle(termin) {
      if (!termin || !termin.start || !termin.ende) return {};

      const startParts = termin.start.split(':');
      const endParts = termin.ende.split(':');

      // 1. Start und Ende in Minuten seit Mitternacht
      const startMin = parseInt(startParts[0]) * 60 + parseInt(startParts[1]);
      const endMin = parseInt(endParts[0]) * 60 + parseInt(endParts[1]);
      const dauerMin = endMin - startMin;

      // 2. Berechnung relativ zum Plan-Start (08:00 Uhr = 480 Min)
      const planStartMin = 420;
      const planGesamtMin = 660; // 14 Stunden (von 08:00 bis 22:00)

      // 3. Position in Prozent berechnen
      const topPercent = ((startMin - planStartMin) / planGesamtMin) * 100;
      const heightPercent = (dauerMin / planGesamtMin) * 100;

      return {
        position: 'absolute',
        top: `${topPercent}%`,
        height: `${heightPercent}%`,
        zIndex: 10
      };
    },
    getFachFarbe(name) {
      // Plan-bewusst: im Diensteinsatzplan zählt ausschließlich currentDiensteinsatzplan
      // (Zweitkraft) – nichts mit Lehrern. Zusätzlich gegen fehlende termine absichern.
      const plan = this.activeCategory === 'diensteinsatzplan'
          ? this.currentDiensteinsatzplan
          : this.currentLehrerstundenplan;
      const termine = (plan && plan.termine) || [];
      const fach = termine.find(f => f.fach === name);

      // Gib die Farbe zurück, oder eine Standardfarbe (z.B. grau), falls nichts gefunden wurde
      return fach ? fach.schulfach_farbe : this.getRandomPastelColor(name);
    },
    // Achte darauf, dass der Name mit dem Aufruf im Template übereinstimmt!
    getTerminStyleExact(termin) {
      const pixelProMinute = 1.2;
      const tagesStartMinuten = 7 * 60; // Plan beginnt um 07:00 Uhr

      const startMin = this.timeToMinutes(termin.start);
      const endeMin = this.timeToMinutes(termin.ende);
      const dauer = endeMin - startMin;

      const topPos = (startMin - tagesStartMinuten) * pixelProMinute;
      const hoehe = dauer * pixelProMinute;

      // Logik für Überlappungen (Nebeneinander), falls zwei Termine gleichzeitig sind
      const gleichzeitige = (this.activeLehrer.termine || []).filter(t =>
          t.tag === termin.tag &&
          ((t.start >= termin.start && t.start < termin.ende) ||
              (termin.start >= t.start && termin.start < t.ende))
      );

      const index = gleichzeitige.findIndex(t => (t.uuid || t.id) === (termin.uuid || termin.id));

      return {
        position: 'absolute',
        top: `${topPos}px`,
        height: `${hoehe - 2}px`,
        left: `5%`,
        width: `90%`,
        backgroundColor: this.getFachFarbe(termin.fach || termin.aktivitaet),
        zIndex: 10 + index,
        // ... restliche Styles (Border, Radius, etc.)
      };
    },
    // Farbe eines Diensteinsatz-Chips: aus der Aktivität ableiten (keine Schulfächer mehr)
    getDienstFarbe(termin) {
      const akt = (this.aktivitaetenMitFarbe || []).find(a =>
          a.id === termin.aktivitaet_id || a.name === termin.aktivitaet
      );
      return akt ? akt.farbe : this.getRandomPastelColor(termin.aktivitaet || 'Aktivität');
    },
    // Positionierung/Styling eines Termin-Chips im Diensteinsatzplan-Raster.
    // Eigene Variante von getTerminStyleExact, damit keine Lehrer-Farb-/Termin-Logik greift.
    getDienstTerminStyle(termin) {
      const pixelProMinute = 1.2;
      const tagesStartMinuten = 7 * 60; // Plan beginnt um 07:00 Uhr

      const startMin = this.timeToMinutes(termin.start);
      const endeMin = this.timeToMinutes(termin.ende);
      const dauer = endeMin - startMin;

      const topPos = (startMin - tagesStartMinuten) * pixelProMinute;
      const hoehe = dauer * pixelProMinute;

      // Überlappungen am selben Tag nebeneinander anordnen
      const termine = this.currentDiensteinsatzplan.termine || [];
      const gleichzeitige = termine.filter(t =>
          t.tag === termin.tag &&
          ((t.start >= termin.start && t.start < termin.ende) ||
              (termin.start >= t.start && termin.start < t.ende))
      );
      const index = Math.max(0, gleichzeitige.findIndex(t => t.termin_id === termin.termin_id));

      return {
        position: 'absolute',
        top: `${topPos}px`,
        height: `${hoehe - 2}px`,
        left: `5%`,
        width: `90%`,
        backgroundColor: this.getDienstFarbe(termin),
        zIndex: 10 + index,
      };
    },
    // Ampelfarbe für das Ist/Soll-Badge in der Diensteinsatz-Stundentafel
    dienstBadgeColor(ist, soll) {
      if (ist === soll) return '#193217'; // exakt erfüllt → grün
      if (ist > soll) return '#3e2023';   // überbucht → rot
      return '#474322';                    // noch offen → gelb
    },
    // Lädt den Diensteinsatzplan einer Zweitkraft (SOLL-Stundentafel + IST-Termine)
    async loadDiensteinsatzplan(zweitkraftId) {
      if (!zweitkraftId || !this.currentSchuljahrId) return;

      try {
        const response = await fetch(`${API_URL}?action=get_diensteinsatzplan&zweitkraft_id=${zweitkraftId}&schuljahr_id=${this.currentSchuljahrId}`);
        const result = await response.json();

        if (result.success && Array.isArray(result.data)) {
          // API liefert ein Array je Zweitkraft; bei gesetzter zweitkraft_id genau ein Eintrag
          const plan = result.data[0] || {};
          this.currentDiensteinsatzplan = {
            ...plan,
            stundentafel: plan.stundentafel || [],
            termine: plan.termine || []
          };
        } else {
          this.currentDiensteinsatzplan = { stundentafel: [], termine: [] };
          const detail = result.message ? `: ${result.message}` : '';
          console.error("Diensteinsatzplan-Fehler:", result);
          this.showStatus((result.error || "Diensteinsatzplan konnte nicht geladen werden") + detail, "error");
        }
      } catch (error) {
        console.error("Fetch-Fehler Diensteinsatzplan:", error);
        this.showStatus("Verbindung zum Server fehlgeschlagen", "error");
      }
    },
    navigate(category) {
      // Sonderfälle ausschließen
      if (category === 'Gesamtplan' || category === 'Dokument(e) importieren') {
        console.log("Spezialfunktion für:", category);
        return;
      }
      // Kategorie setzen und Ansicht wechseln
      this.activeCategory = category;
      this.view = 'list';
      history.pushState({view: 'list', category: category}, '', '#list');
    },
    openQuickAdd(type, index = null) {
      console.log("QuickAdd aufgerufen für:", type, "Index:", index); // Zum Debuggen in der Konsole
      // 1. Das aktuell offene Dropdown schließen
      this.activeDropdown = null;
      this.activeTerminIndex = index;

      if (type === 'raum') {
        // Falls das Fach-Modal offen war, merken wir uns das!
        if (this.showNewFachModal) {
          this.returnToFachAfterRaum = true;
          this.showNewFachModal = false;
        }

        this.editingRaum = {
          id: null,
          schuljahr_id: this.currentSchuljahrId,
          name: '',
          unterrichtsfach: '',
          immer_verfuegbar: true,
          verfuegbarkeiten: [] // Bleibt leer, damit keine leere Zeile erscheint
        };
        // Modal öffnen
        this.showRaumModal = true;
      } else if (type === 'erstkraft' || type === 'zweitkraft') {
        // savePerson entscheidet anhand von personModalType, ob save_erstkraft
        // oder save_zweitkraft aufgerufen wird – hier korrekt setzen, sonst
        // wird eine neue Zweitkraft faelschlich als Erstkraft gespeichert.
        this.personModalType = type;
        // Wir nutzen ein gemeinsames Objekt für die neue Person
        this.editingPerson = {
          name: '',
          type: type // Merken uns, ob es 'erstkraft' oder 'zweitkraft' ist
        };
        this.showPersonModal = true;
      } else if (type === 'fach') {
        this.editingFach = {
          name: '',
          farbe: getInitialColor(), // Ein schönes Standard-Blau
          benoetigte_raeume: [] // Array für die IDs
        };
        this.showNewFachModal = true;
      } else if (type === 'aktivitaet') {
        this.editingAktivitaet = {
          name: '',
          typ: '',
          einsatzort: '',
          termine: [
            {
              tag: '',
              uhrzeit: '13:00', // Wichtig: Gleicher Name wie im v-model!
              endzeit: '13:45',
              raeume: [],  // Muss als Array existieren wegen .includes()
              verantwortliche: []
            }
          ]
        };
        this.showNewAktivitaetModal = true;
      }
    },
    openQuickAddRaumFromFach(index = null) {
      this.isQuickAddingForFach = true; // Essentiell!
      this.openQuickAdd('raum', index);
    },
    async saveErstkraft() {
      if (this.currentErstkraft.pflichtstunden - (this.currentErstkraft.upz + this.currentErstkraft.ermaessigung) < 0) {
        this.showStatus("Zu viele UPZ oder ermäßigte Stunden", "error");
        return;
      }
      try {
        const payload = {
          ...this.currentErstkraft,
          schuljahr_id: this.currentSchuljahrId
        };

        const response = await fetch(`${API_URL}?action=save_erstkraft`, {
          method: 'POST',
          body: JSON.stringify(payload)
        });

        const result = await response.json();
        if (result.success) {
          this.showStatus("Erstkraft erfolgreich gespeichert!", "success");
          await this.loadFromDatabase(); // Listen neu laden
          this.view = 'list';
          await this.loadLehrerverfuegbarkeiten();
        }
      } catch (e) {
        this.showStatus("Fehler beim Speichern", "error");
      }
    },
    async saveFach() {
      if (!this.editingFach || this.editingFach === null) {
        console.error("Speichern abgebrochen: editingFach ist null");
        return;
      }

      try {
        const payload = {
          id: this.editingFach.id || null,
          schuljahr_id: this.currentSchuljahrId,
          name: this.editingFach.name,
          farbe: this.editingFach.farbe,
          benoetigte_raeume: JSON.stringify(this.editingFach.benoetigte_raeume || [])
        };

        const response = await fetch(`${API_URL}?action=save_fach`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (result.success || result.id) {
          // 1. Liste der Fächer im Hintergrund aktualisieren
          await this.loadFaecher();

          const neuesFachObjekt = {
            id: result.id,
            schuljahr_id: this.currentSchuljahrId,
            name: this.editingFach.name,
            farbe: this.editingFach.farbe,
            // Wir speichern es so, wie es aus der DB kommen würde
            benoetigte_raeume: JSON.stringify(this.editingFach.benoetigte_raeume || [])
          };

          // 2. UI zurücksetzen
          this.showFachModal = false; // Falls es ein Modal ist
          if (!this.showNewFachModal) {
            this.view = 'home';
          } else {
            this.showNewFachModal = false;
            this.verfuegbareFaecher.push(neuesFachObjekt);
          }

          this.editingFach = {name: '', benoetigte_raeume: []};
          if (this.returnToFachAfterAddFach) {
            this.returnToFachAfterAddFach = false;
            this.addNewTafelItem(neuesFachObjekt.id);
          }
        }
      } catch (e) {
        // Hier fangen wir den Fehler ab, damit die App nicht abstürzt
        console.error("Fehler beim Speichern des Fachs:", e);
        this.elliAlert("Das Fach konnte nicht gespeichert werden. Details siehe Konsole.");
      }
    },
    async savePerson() {
      try {
        // 1. Validierung
        if (!this.editingPerson.name || !this.editingPerson.name.trim()) {
          this.showStatus("Bitte einen Namen eingeben.");
          return;
        }

        let payload = {
          id: this.editingPerson.id || null,
          schuljahr_id: this.currentSchuljahrId,
          name: this.editingPerson.name,
          kuerzel: this.editingPerson.kuerzel || this.editingPerson.name.substring(0, 3).toUpperCase(),
          farbe: this.editingPerson.farbe || getInitialColor(),
          textfarbe: this.editingPerson.textfarbe || '#9f9898',
          pflichtstunden: this.editingPerson.pflichtstunden || 0,
          ermaessigung: this.editingPerson.ermaessigung || 0
        };

        // Spezifische Felder je nach Typ hinzufügen
        if (this.personModalType === 'erstkraft') {
          payload.upz = this.editingPerson.upz || 0;
          payload.titel = this.editingPerson.titel || '';
          payload.faecher = this.editingPerson.faecher || '';
        } else {
          // Zweitkraft Felder
          payload.typ = this.editingPerson.typ || 'Lehrkraft';
          payload.einsatzort = this.editingPerson.einsatzort || '';
          payload.grund_ermaessigung = this.editingPerson.grund_ermaessigung || '';
        }

        // 3. API Call (Action entscheiden: erstkraft oder zweitkraft)
        const action = this.personModalType === 'erstkraft' ? 'save_erstkraft' : 'save_zweitkraft';

        const response = await fetch(`${API_URL}?action=${action}`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (result.success || result.id) {
          const neueId = result.id;
          const praefix = this.personModalType === 'erstkraft' ? 'e-' : 'z-';
          const personKey = praefix + neueId;

          await this.loadFromDatabase();
          if (this.currentActivity && this.activeTerminIndex !== null) {
            const termin = this.currentActivity.termine[this.activeTerminIndex];
            if (termin) {
              if (!termin.verantwortliche) termin.verantwortliche = [];

              // Den neuen Key (z.B. "e-15") zum Array hinzufügen
              if (!termin.verantwortliche.includes(personKey)) {
                termin.verantwortliche.push(personKey);
              }
            }
          }  // FALL B: Wir kommen vom Stundenplan-Drop (NEU)
          else if (this.pendingAssignment && this.personModalType === 'erstkraft') {
            // Wir erstellen ein "Lehrer-Objekt" für die Bestätigung
            const neueLehrkraft = {
              id: neueId,
              name: payload.name,
              kuerzel: payload.kuerzel
            };

            // Wir nutzen deine Bestätigungs-Logik von vorhin
            this.confirmAssignment(neueLehrkraft);
          }

          // UI AUFRÄUMEN (verhindert das Einfrieren)
          this.showPersonModal = false;
          this.activeTerminIndex = null;
          this.activeDropdown = null; // Schließt das Dropdown-System
          this.editingPerson = {name: ''};
        }
      } catch (error) {
        console.error("Fehler beim Speichern der Person:", error);
        this.elliAlert("Speichern fehlgeschlagen.");
      }
    },
    async saveRaum() {
      try {
        const response = await fetch(`${API_URL}?action=save_raum`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(this.editingRaum)
        });
        const result = await response.json();
        if (result.id) {
          const neueRaumId = parseInt(result.id);
          await this.loadRaeume(); // Liste aktualisieren
          await this.loadRaumVerfuegbarkeiten(); // Liste für isRaumVerfuegbar aktualisieren (sonst ist der neue Raum dort unbekannt)
          // FALL 1: Wir kommen aus einem Termin (Quick-Add während Aktivitäts-Bearbeitung)
          if (this.activeTerminIndex !== null && this.currentActivity) {
            const termin = this.currentActivity.termine[this.activeTerminIndex];
            if (termin) {
              if (!termin.raeume) {
                termin.raeume = [];
              }
              // Füge die neue ID zum Array hinzu (für dein v-for/includes im Template)
              if (!termin.raeume.includes(neueRaumId)) {
                termin.raeume.push(neueRaumId);
              }
              termin.raum_id = neueRaumId;
            }
            this.view = 'editor'; // Wir bleiben im Aktivitäts-Editor
          } // FALL 2: Wir kommen aus dem Schulfach-Editor
          else if (this.isQuickAddingForFach) {
            if (!this.editingFach.benoetigte_raeume) {
              this.editingFach.benoetigte_raeume = [];
            }
            if (!this.editingFach.benoetigte_raeume.includes(neueRaumId)) {
              this.editingFach.benoetigte_raeume.push(neueRaumId);
            }
            this.isQuickAddingForFach = false;
            // Hier KEIN this.view = 'home'
            this.view = 'editor'; // Wir bleiben im Aktivitäts-Editor
          } else if (this.returnToFachAfterRaum) {
            this.showRaumModal = false;
            this.showNewFachModal = true; // Zurück zum Fach
            this.returnToFachAfterRaum = false;
          } else {
            this.view = 'home';
          }

          this.showRaumModal = false;
          this.activeTerminIndex = null;
          this.editingRaum = {name: '', unterrichtsfach: '', immer_verfuegbar: true, verfuegbarkeiten: []};
          this.activeDropdown = null;
          await this.loadFromDatabase();
        }
      } catch (e) {
        console.error("Fehler beim Speichern:", e);
      }
    },
    async saveZweitkraft() {
      try {
        // 1. Gesamtsumme der Pflichtstunden aus dem Array berechnen
        const gesamtPflicht = this.currentZweitkraft.pflichtstunden_masse.reduce((sum, m) => {
          return sum + (parseFloat(m.stunden) || 0);
        }, 0);

        // 2. UPZ berechnen (Pflicht - Ermäßigung)
        const ermaessigung = parseFloat(this.currentZweitkraft.ermaessigung) || 0;
        this.currentZweitkraft.upz = (gesamtPflicht - ermaessigung).toFixed(2);

        // 3. Payload vorbereiten
        const payload = {
          ...this.currentZweitkraft,
          schuljahr_id: this.currentSchuljahrId
        };

        // Wir nutzen fetch, genau wie bei der Erstkraft
        const response = await fetch(`${API_URL}?action=save_zweitkraft`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (result.success) {
          this.showStatus("Zweitkraft erfolgreich gespeichert!", "success");

          // Listen neu laden, damit die Änderungen überall sichtbar sind
          await this.loadFromDatabase();

          // Zurück zur Übersicht/Liste
          this.view = 'home';
        } else {
          this.showStatus("Fehler: " + result.error, "error");
        }
      } catch (e) {
        console.error("API-Fehler:", e);
        this.showStatus("Verbindung zur Datenbank fehlgeschlagen", "error");
      }
    },
    selectEinsatzort(ort) {
      if (this.currentActivity) {
        this.currentActivity.einsatzort = ort;
      }
      this.activeDropdown = null;
    },
    selectType(t) {
      // Wenn du im Modus "Zweitkraft" bist:
      if (this.activeCategory === 'zweitkraft') {
        this.currentZweitkraft.typ = t;
      } else if (this.showNewAktivitaetModal) {
        this.editingAktivitaet.typ = t;
      }
      // Falls du dasselbe Dropdown auch für Aktivitäten nutzt:
      else if (this.currentActivity) {
        this.currentActivity.typ = t;
      }

      this.activeDropdown = null;
    },
    selectFachForTafel(obj, typePrefix) {
      // type ist 'f' für Fach oder 'a' für Aktivität
      this.selectedType = typePrefix;
      this.selectedUniqueKey = typePrefix + '-' + obj.id;
      this.selectedFachId = obj.id;

      this.newTafelEntry.name = obj.name;
      this.newTafelEntry.farbe = obj.farbe;

      // 3. Prüfen, ob dieses Fach bereits in der Stundentafel existiert
      const existierenderEintrag = this.stundentafel.find(s => s.name === obj.name);

      if (existierenderEintrag) {
        // FALL: Bearbeiten -> Bestehende Werte in das Modal laden
        this.newTafelEntry.soll_klassenverbund = existierenderEintrag.soll_klassenverbund || 0;
        this.newTafelEntry.soll_differenzierung = existierenderEintrag.soll_differenzierung || 0;

        // Für die Zeitfelder (Lehrer-Aktivitäten)
        this.newTafelEntry.soll_stunden = existierenderEintrag.soll_stunden || 0;
        this.newTafelEntry.soll_minuten = existierenderEintrag.soll_minuten || 0;
      } else {
        // FALL: Neu hinzufügen -> Alles auf Null zurücksetzen
        this.newTafelEntry.soll_klassenverbund = 0;
        this.newTafelEntry.soll_differenzierung = 0;
        this.newTafelEntry.soll_stunden = 0;
        this.newTafelEntry.soll_minuten = 0;
      }
    }, // In deinen methods:
    addNewTafelItem(item = null) {
      this.resetModal();

      if (item) {
        const counts = {};

        this.selectedUniqueKey = 'f-' + this.faecher.find(r => r.name === item.name)?.id;

        // 1. Datenerfassung: Wir zählen, was AKTUELL im Plan liegt
        let termineImPlan = [];
        if (this.activeCategory === 'schuelerstundenplan') {
          termineImPlan = this.currentSchuelerStundenPlan?.termine || [];
        } else if (this.activeCategory === 'lehrerstundenplan') {
          const lehrerId = this.activeLehrer?.id;
          const plan = (this.currentLehrerstundenplan && lehrerId) ? this.currentLehrerstundenplan[lehrerId] : null;
          termineImPlan = plan?.termine || [];
        }

        termineImPlan.forEach(t => {
          // Wir nehmen den Namen, egal ob er in fachName oder aktivitaet_name steht
          const name = t.fachName || t.aktivitaet_name || (t.display ? t.display.fachName : null);
          if (name) {
            if (!counts[name]) counts[name] = {kv: 0, diff: 0, originalTermin: t};

            // Unterscheidung KV / Diff
            if (this.activeCategory === 'lehrerstundenplan') {
              if (t.rolle === 'zweit' || t.is_differenzierung) counts[name].diff++;
              else counts[name].kv++;
            } else {
              if (t.is_differenzierung) counts[name].diff++;
              else counts[name].kv++;
            }
          }
        });

        const tempCounts = {...counts};

        // 2. Mapping gegen die offizielle Stundentafel
        const result = this.stundentafel.map(sollFach => {
          const istData = tempCounts[sollFach.name] || {kv: 0, diff: 0};
          delete tempCounts[sollFach.name];

          return {
            ...sollFach,
            ist_klassenverbund: istData.kv,
            ist_differenzierung: istData.diff,
            ist: istData.kv + istData.diff
          };
        });

        // 3. WILDCARDS (Alles was im Plan ist, aber nicht in der Stundentafel)
        Object.keys(tempCounts).forEach(name => {
          const istData = tempCounts[name];

          // --- JETZT NEU: Suche in ALLEN verfügbaren Quellen nach der Farbe ---
          const quelleFach = this.verfuegbareFaecher.find(f => f.name === name);
          const quelleAktivitaet = this.aktivitaeten.find(a => a.name === name);

          // Priorität: Erst Fach-Farbe, dann Aktivitäts-Farbe, dann Zufall/Grau
          const wildcardFarbe = quelleFach?.farbe || quelleAktivitaet?.farbe || this.getFachFarbe(name) || '#9e9e9e';

          result.push({
            id: 'wildcard-' + name,
            name: name,
            soll: 0,
            soll_klassenverbund: 0,
            soll_differenzierung: 0,
            ist_klassenverbund: istData.kv,
            ist_differenzierung: istData.diff,
            ist: istData.kv + istData.diff,
            isWildcard: true,
            farbe: wildcardFarbe
          });
        });
        this.newTafelEntry = {
          id: this.faecher.find(r => r.name === item.name)?.id,
          soll_klassenverbund: item.soll_klassenverbund,
          soll_differenzierung: item.soll_differenzierung,
          farbe: item.farbe,
          name: item.name
        };
        this.selectedType = 'f';
        this.selectedFachId = this.faecher.find(r => r.name === item.name)?.id;
      } else {
        // Neuanlage: Reset der Felder
        this.selectedFachId = null;
        this.newTafelEntry = {
          id: null,
          name: '',
          farbe: this.getRandomDarkColor(),
          soll_klassenverbund: 0,
          soll_differenzierung: 0
        };
        this.sliderSteps = 0;
        this.sliderSteps_differenzierung = 0;
      }
      this.showTafelSelectionModal = true;
    },
    toggleDropdown(id) {
      // Wenn das geklickte Dropdown schon offen ist -> schließen (null)
      // Ansonsten -> die neue ID setzen
      this.activeDropdown = this.activeDropdown === id ? null : id;
    },
    // Setzt den Tag für den richtigen Termin und schließt das Dropdown
    selectTagForTermin(index, tag) {
      if (this.activeCategory === 'aktivitaet') {
        this.currentActivity.termine[index].tag = tag;
        console.log(`Termin ${index} auf ${tag} gesetzt:`, this.currentActivity.termine[index]);
      } else {
        this.editingAktivitaet.termine[index].tag = tag;
      }
      this.activeDropdown = null;
    },
    showStatus(message, type = 'success') {
      this.snackbar.message = message;
      this.snackbar.type = type;
      this.snackbar.show = true;

      // Nach 3 Sekunden automatisch schließen
      setTimeout(() => {
        this.snackbar.show = false;
      }, 3000);
    },
    toggleSelection(array, value) {
      // Wir wandeln alles in Strings um für einen sicheren Vergleich
      const valStr = value.toString();
      const index = array.findIndex(item => item.toString() === valStr);

      if (index > -1) {
        array.splice(index, 1);
      } else {
        array.push(valStr);
      }
    },
    toggleRaumAnforderung(raumId) {
      if (!this.editingFach.benoetigte_raeume) {
        this.editingFach.benoetigte_raeume = [];
      }
      const index = this.editingFach.benoetigte_raeume.indexOf(raumId);
      if (index > -1) {
        this.editingFach.benoetigte_raeume.splice(index, 1);
      } else {
        this.editingFach.benoetigte_raeume.push(raumId);
      }
    },
    goBack() {
      // Wenn wir in der Listenansicht sind, geh zurück zur Startseite
      this.editingFach = null;
      this.editingRaum = null;
      this.currentActivity = null;
      this.currentSchuelerStundenPlan = {termine: []};
      this.stundentafel = [];
      this.planData = {};
      this.activeDropdown = null;
      this.showSettings = false;
      if (this.view === 'list') {
        this.view = 'home';
        this.activeCategory = ''; // Kategorie zurücksetzen
      }
      // Falls du später eine Editor-Ansicht hast:
      else if (this.view === 'editor') {
        this.view = 'list';
        this.loadFromDatabase();
      }
      if (!this.isHardwareBack) {
        history.pushState({view: 'home'}, '', '#home');
      }
    },
    handleHardwareBack(event) {
      // Wird aufgerufen, wenn der Browser-Zurück-Button gedrückt wird
      if (event.state && event.state.view === 'home') {
        this.goBack(true);
      } else if (!event.state && this.view === 'list') {
        // Fallback, wenn kein State vorhanden ist (z.B. erster Aufruf)
        this.goBack(true);
      }
    },
    async loadSettings() {
      const response = await fetch(`${API_URL}?action=get_settings`);
      const data = await response.json();
      if (data.nutzername) {
        this.nutzerName = data.nutzername;
      }
    },
    async saveAddressManual() {
      if (!this.currentSchuljahrId) {
        this.elliAlert("Bitte wählen Sie zuerst ein Schuljahr aus.");
        return;
      }

      try {
        const response = await fetch(`${API_URL}?action=save_address`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            schuljahr_id: this.currentSchuljahrId,
            adresse: this.schule.adresse
          })
        });
        const resData = await response.json();
        if (resData.success) {
          this.showStatus("Adresse erfolgreich gespeichert!");
          // Optional: Schuljahre neu laden, damit die Daten im State aktuell sind
          await this.fetchSchuljahre();
        } else {
          this.showStatus("Fehler: " + resData.error, "error");
        }
      } catch (e) {
        console.error(e);
        this.showStatus("Server-Verbindungsfehler", "error");
      }
    },
    async saveNutzerName() {
      // Wir speichern den Namen in der DB
      await fetch(`${API_URL}?action=save_setting`, {
        method: 'POST',
        body: JSON.stringify({
          schluessel: 'nutzername',
          wert: this.nutzerName
        })
      });
    },
    selectYear(yearObj) {
      this.currentSchuljahrId = yearObj.id;
      this.currentSchuljahr = yearObj.schuljahr;

      this.aktivitaeten = [];
      this.erstkraefte = [];
      this.zweitkraefte = [];
      this.raeume = [];

      this.editingFach = null;
      this.editingRaum = null;
      this.currentActivity = null;
      this.currentSchuelerStundenPlan = {termine: []};
      this.stundentafel = [];
      this.planData = {};
      this.activeDropdown = null;

      // Beim Wechseln des Jahres die Adresse korrekt laden (String -> Objekt)
      if (yearObj.adresse) {
        try {
          this.schule.adresse = typeof yearObj.adresse === 'string'
              ? JSON.parse(yearObj.adresse)
              : yearObj.adresse;
        } catch (e) {
          this.schule.adresse = {name: '', strasse: '', stadt: ''};
        }
      } else {
        this.schule.adresse = {name: '', strasse: '', stadt: ''};
      }
      this.loadFromDatabase();
      this.loadSchuelerStundenPlaene();
      this.showSettings = false;
    },
    toggleMenu() {
      this.showSettings = !this.showSettings;
    },
    // Verzögert das Speichern um 500ms (Debouncing)
    triggerAutoSave() {
      // Wenn wir gerade laden, nicht speichern!
      if (this.isInitialLoading) return;

      clearTimeout(this.saveTimeout);
      this.saveTimeout = setTimeout(() => {
        this.saveToDatabase();
      }, 500);
    },
    async saveToDatabase() {
      if (!this.currentSchuljahrId) return;

      try {
        // Wir nutzen hier 'save_address', damit PHP weiß, dass es kein AG-Eintrag ist
        await fetch(`${API_URL}?action=save_address`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            schuljahr_id: this.currentSchuljahrId, // WICHTIG: Die ID nutzen
            adresse: this.schule.adresse
          })
        });
        console.log("Schuladresse erfolgreich synchronisiert.");
      } catch (e) {
        console.error("Fehler beim Speichern der Adresse:", e);
      }
    },
    async loadFaecher() {
      if (!this.currentSchuljahrId) return;
      try {
        const response = await fetch(`${API_URL}?action=load_faecher&schuljahr_id=${this.currentSchuljahrId}`);
        const data = await response.json();

        // Falls die API ein Array schickt, zuweisen, sonst leeres Array
        this.faecher = Array.isArray(data) ? data : [];

        // WICHTIG: Falls die benoetigte_raeume als JSON-String in der DB liegen,
        // müssen wir sie hier wieder in ein Array umwandeln
        this.faecher = this.faecher.map(f => ({
          ...f,
          benoetigte_raeume: typeof f.benoetigte_raeume === 'string'
              ? JSON.parse(f.benoetigte_raeume)
              : (f.benoetigte_raeume || []),

          farbe: (f.farbe && f.farbe !== '')
              ? f.farbe
              : this.getRandomDarkColor()
        }));
      } catch (e) {
        console.error("Fehler beim Laden der Fächer:", e);
        this.faecher = [];
      }
    },
    async loadFromDatabase() {
      // Sicherheitscheck: Haben wir eine ID?
      if (!this.currentSchuljahrId) {
        return;
      }

      this.isInitialLoading = true;
      try {
        // ACHTUNG: Der Parameter muss schuljahr_id heißen, passend zur api.php
        const url = `${API_URL}?action=load_editor_data&schuljahr_id=${this.currentSchuljahrId}`;
        const res = await fetch(url);
        const data = await res.json();

        if (data.error) {
          console.error("Datenbank-Fehler:", data.error);
        } else {
          // Listen befüllen
          this.erstkraefte = data.erstkraefte || [];
          this.zweitkraefte = data.zweitkraefte || [];
          this.raeume = data.raeume || [];
          this.aktivitaeten = data.aktivitaeten || []; // Falls vorhanden
          this.aktivitaeten.forEach(a => {
            a.farbe = this.getRandomPastelColor(a.name);
          })
          this.faecher = data.faecher || [];
          this.verfuegbareFaecher = data.faecher || [];
        }
        console.log("Zu editierende Daten", this.erstkraefte);
        console.log("Zu editierende Daten", this.aktivitaeten);
        console.log("Zu editierende Daten", this.raeume);
      } catch (e) {
        console.error("Verbindungsfehler:", e);
      }
    },
    async loadSchuelerStundenPlaene() {
      if (!this.currentSchuljahrId) return;

      try {
        const url = `${API_URL}?action=load_schuelerstundenplaene&schuljahr_id=${this.currentSchuljahrId}`;
        const res = await fetch(url);
        const result = await res.json();

        if (result.success) {
          // Hier wird dein Array befüllt, das currentItems() dann zurückgibt
          this.schuelerstundenplaene = result.plaene;
        } else {
          console.error("Fehler von API:", result.error);
        }
      } catch (e) {
        console.error("Netzwerkfehler:", e);
      }
    },
    async loadSchuelerStundenPlan(klasseId) {
      if (!klasseId) return;
      try {
        const response = await fetch(`${API_URL}?action=get_schuelerstundenplan&klasseId=${klasseId}`);
        const result = await response.json();

        if (result.success && result.data) {
          // 1. Stundentafel mappen (für die farbigen Fortschrittsbalken)
          this.stundentafel = (result.data.stundentafel || []).map(soll => {
            const fach = this.faecher?.find(f => String(f.id) === String(soll.schulfach_id));
            return {
              ...soll,
              name: fach ? fach.name : 'Unbekanntes Fach',
              farbe: fach ? fach.farbe : '#e0e0e0',
              soll_klassenverbund: soll.verbund || 0,
              soll_differenzierung: soll.diff || 0,
              gesamt: (soll.verbund || 0) + (soll.diff || 0)
            };
          });

          // 2. Zeitraster aufbereiten
          const aufbereitetesRaster = (result.data.zeitRaster || []).map(s => ({
            id: s.stunden_index,
            start: (s.startzeit || '').substring(0, 5),
            ende: (s.endzeit || '').substring(0, 5)
          }));

          // 3. Den Plan direkt übernehmen (Termine sind im PHP schon fertig!)
          const geladenerPlan = result.data.plan;

          // 4. Synchronisation mit den 'exposed' Refs für das Grid
          if (this.$.exposed) {
            if (this.$.exposed.zeitRaster) this.$.exposed.zeitRaster.value = aufbereitetesRaster;
            if (this.$.exposed.termine) this.$.exposed.termine.value = geladenerPlan.termine;
          }

          // 5. Zuweisung an den reaktiven State
          this.currentSchuelerStundenPlan = geladenerPlan;

          // UI-Funktionen triggern
          await this.$nextTick(() => {
            if (typeof this.updateGridDisplay === 'function') this.updateGridDisplay();
            if (typeof this.updateIstStunden === 'function') this.updateIstStunden();
          });
        }
      } catch (e) {
        console.error("Fehler beim Laden des Stundenplans:", e);
      }
    },
    formatTime(mins) {
      const h = Math.floor(mins / 60).toString().padStart(2, '0');
      const m = (mins % 60).toString().padStart(2, '0');
      return `${h}:${m}`;
    },
    generateDefaultRaster() {
      let start = 8 * 60 + 15;
      return Array.from({length: 10}, (_, i) => ({
        id: i + 1,
        start: this.formatTime(start + i * 45),
        ende: this.formatTime(start + (i + 1) * 45)
      }));
    },
    async saveActivity() {
      try {
        // 1. Datenquelle sicher wählen
        const rawSource = this.editingAktivitaet || this.currentActivity;
        if (!rawSource) throw new Error("Keine Aktivitätsdaten gefunden.");

        const activityData = JSON.parse(JSON.stringify(this.currentActivity || rawSource));

        if (!this.currentSchuljahrId) {
          this.showStatus("Kein Schuljahr ausgewählt!", "error");
          return;
        }
        activityData.schuljahr_id = this.currentSchuljahrId;

        if (activityData.termine) {
          activityData.termine.forEach(t => {
            // A) Lehrer automatisch setzen, falls im Lehrerplan-Modus
            if (this.activeCategory === 'lehrerstundenplan' && this.activeLehrer) {
              if (!t.verantwortliche || t.verantwortliche.length === 0) {
                t.verantwortliche = [`e-${this.activeLehrer.id}`];
              }
            }

            // B) Verantwortliche für PHP-API aufbereiten
            if (t.verantwortliche) {
              t.verantwortliche = t.verantwortliche.map(v => {
                if (typeof v === 'string' && v.includes('-')) {
                  const [prefix, id] = v.split('-');
                  return {id: parseInt(id), type: prefix === 'e' ? 'erst' : 'zweit'};
                }
                return v;
              });
            }

            // C) Räume sicherstellen (Array-Format für PHP)
            if (!Array.isArray(t.raeume)) {
              t.raeume = t.raum_id ? [parseInt(t.raum_id)] : [];
            }
          });
        }

        // 2. Request
        const url = typeof API_URL !== 'undefined' ? `${API_URL}?action=save_activity` : 'api.php?action=save_activity';

        const response = await fetch(url, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(activityData)
        });

        const responseText = await response.text();
        let result;
        try {
          result = JSON.parse(responseText);
        } catch (e) {
          throw new Error("Server-Fehler: " + responseText);
        }

        if (result.success) {
          this.showStatus('Erfolgreich gespeichert!');

          // UI Updates
          await this.loadFromDatabase(); // Lade alles frisch

          if (this.activeLehrer) {
            this.lehrerMitTerminen(this.activeLehrer.id);
          }

          if (this.activeCategory === 'aktivitaet') {
            this.view = 'home';
          } else {
            this.showNewAktivitaetModal = false;
          }
          this.editingAktivitaet = null;
        } else {
          throw new Error(result.error || 'Fehler beim Speichern');
        }
      } catch (e) {
        console.error("Speicher-Fehler:", e);
        this.showStatus(e.message, 'error');
      }
    },
  }
  ,
  async created() {
    try {
      // 1. Zuerst die Schuljahre laden und die currentSchuljahrId setzen
      await this.fetchSchuljahre();

      // 1b. Erststart: Ohne Schuljahr geht nichts -> Nutzer zwingend anlegen lassen
      if (!this.schuljahre || this.schuljahre.length === 0) {
        this.showOnboardingModal = true;
        return;
      }

      // 2. Erst wenn wir eine ID haben, die restlichen Daten laden
      if (this.currentSchuljahrId) {
        await this.loadFromDatabase();
      }
    } catch (e) {
      console.error("Initialisierungsfehler:", e);
    }
  }
  ,
  mounted() {
    history.replaceState({view: 'home'}, '', '#home');
    window.addEventListener('popstate', this.handleHardwareBack);
    this.loadSettings();

    // Der robuste Klick-Handler:
    window.addEventListener('click', (e) => {
      // Wir prüfen, ob der Klick NICHT innerhalb eines Select-Wrappers war
      if (!e.target.closest('.custom-select-wrapper')) {
        this.showTypeDropdown = false;
        this.activeTagIndex = null;
        this.activeVerantIndex = null;
        this.activeRaumIndex = null;
        const isInsideDropdown = e.target.closest('.custom-select-wrapper');
        if (!isInsideDropdown) {
          this.activeDropdown = null;
          this.showTypeDropdown = false;
        }
      }
    });
  }
  ,
  unmounted() {
    // Sauber aufräumen, wenn die Komponente zerstört wird
    window.removeEventListener('popstate', this.handleHardwareBack);
  }
  ,
  watch: {
    // Immer wenn die Schuljahr-ID geändert wird...
    currentSchuljahrId: {
      immediate: true,
      handler(newId) {
        if (newId) {
          // 1. Zurück zur Hauptansicht schicken, falls wir im Editor waren
          if (this.view === 'editor') {
            this.view = 'home';
          }
          // 2. Bestehende Logik: Daten für das neue Jahr laden
          this.loadFromDatabase();
          this.loadLehrerverfuegbarkeiten();
          // Räume unabhängig laden – NICHT nur als Nebeneffekt von
          // loadLehrerverfuegbarkeiten(), da dessen API-Call sonst diese
          // Liste mit sich reißen kann, wenn er fehlschlägt.
          this.loadRaumVerfuegbarkeiten();
          // 3. (Optional) Sidebar schließen, falls sie noch offen ist
          this.showSettings = false;
        }
      }
    }
    ,
    // Optional: Wenn du willst, dass beim Wechsel der Kategorie auch neu geladen wird
    activeCategory() {
      this.loadFromDatabase();
      this.loadSchuelerStundenPlaene();
      this.loadLehrerverfuegbarkeiten();
      this.loadKlassenVerfuegbarkeiten();
      // Unabhängig von loadLehrerverfuegbarkeiten() laden, damit die
      // Raumbelegungsplan-Liste auch dann erscheint, wenn dessen Aufruf
      // aus einem anderen Grund fehlschlägt.
      this.loadRaumVerfuegbarkeiten();
    }
  }
  ,
}
</script>