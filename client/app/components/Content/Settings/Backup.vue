<template>

  <div class="settings-box" v-if=" ! loading">
    <div class="login-error" v-if="config.env === 'demo'"><span>Data cannot be changed in the demo</span></div>

    <a :href="exportLink" class="setting-btn">{{ lang('export button') }}</a>
    <a :href="exportProgressLink" class="setting-btn">Export watched CSV</a>

    <form class="login-form" @submit.prevent="importMovies()">
      <span class="import-info">{{ lang('or divider') }}</span>
      <input type="file" @change="upload" class="file-btn" required>
      <span class="userdata-changed"><span v-if="uploadSuccess">{{ lang('success import') }}</span></span>
      <div class="import-actions">
        <input type="submit" :value="lang('import button')">
        <button type="button" @click="importMovies(true)" class="setting-btn import-add-btn">Import and add</button>
      </div>
    </form>

    <div class="import-ai">
      <h2>Import with AI</h2>
      <p>Paste messy watch data here. The AI will convert it to the simplified FloMeh JSON format.</p>
      <p>Use seen_until: true only when every episode up to that point should be marked as watched.</p>
      <textarea v-model="aiText" placeholder="Example: The Boys, season 3 episode 1, tmdb 76479, seen until here"></textarea>
      <div class="import-actions">
        <button type="button" @click="formatWithAi()" class="setting-btn import-add-btn" :disabled="aiLoading">Format with AI</button>
      </div>
      <span class="userdata-changed"><span v-if="aiMessage">{{ aiMessage }}</span></span>
      <textarea v-if="aiJson" v-model="aiJson" class="import-ai-json"></textarea>
      <div class="import-actions" v-if="aiJson">
        <button type="button" @click="importAiJson(false)" class="setting-btn">Import AI replacing library</button>
        <button type="button" @click="importAiJson(true)" class="setting-btn import-add-btn">Import AI and add</button>
      </div>
    </div>
  </div>

</template>

<script>
  import { mapState, mapMutations } from 'vuex';
  import MiscHelper from '../../../helpers/misc';

  import http from 'axios';

  export default {
    mixins: [MiscHelper],

    data() {
      return {
        config: window.config,
        uploadSuccess: false,
        uploadedFile: null,
        aiText: '',
        aiJson: '',
        aiLoading: false,
        aiMessage: ''
      }
    },

    computed: {
      ...mapState({
        loading: state => state.loading
      }),

      exportLink() {
        return config.api + '/export';
      },

      exportProgressLink() {
        return config.api + '/export-progress-csv';
      }
    },

    methods: {
      ...mapMutations([ 'SET_LOADING' ]),

      upload(event) {
        const file = event.target.files || event.dataTransfer.files;

        this.uploadedFile = new FormData();
        this.uploadedFile.append('import', file[0]);
      },

      importMovies(addToExisting = false) {
        if(this.uploadedFile) {
          const confirmMessage = addToExisting
            ? 'The file will be added to the existing library. Existing matching items will be updated.'
            : this.lang('import warn');
          const confirm = window.confirm(confirmMessage);

          if(confirm) {
            this.SET_LOADING(true);

            const endpoint = addToExisting ? 'import-add' : 'import';

            http.post(`${config.api}/${endpoint}`, this.uploadedFile).then(() => {
              this.SET_LOADING(false);
              this.uploadSuccess = true;
            }, error => {
              this.SET_LOADING(false);
              alert('Error: ' + error.response.data);
            });
          }
        }
      },

      formatWithAi() {
        if( ! this.aiText.trim()) {
          alert('Paste some text first.');
          return;
        }

        this.aiLoading = true;
        this.aiMessage = '';

        http.post(`${config.api}/import-ai-format`, {text: this.aiText}).then(response => {
          this.aiJson = response.data.json;
          this.aiMessage = `AI formatted ${response.data.items_count} item(s). Review the JSON before importing.`;
          this.aiLoading = false;
        }, error => {
          this.aiLoading = false;
          alert('Error: ' + (error.response ? error.response.data : error));
        });
      },

      importAiJson(addToExisting = true) {
        if( ! this.aiJson.trim()) {
          return;
        }

        const confirmMessage = addToExisting
          ? 'The AI JSON will be added to the existing library. Existing matching items will be updated.'
          : this.lang('import warn');

        if( ! window.confirm(confirmMessage)) {
          return;
        }

        const formData = new FormData();
        const blob = new Blob([this.aiJson], {type: 'application/json'});
        formData.append('import', blob, 'import-ai.json');

        this.SET_LOADING(true);

        const endpoint = addToExisting ? 'import-add' : 'import';

        http.post(`${config.api}/${endpoint}`, formData).then(() => {
          this.SET_LOADING(false);
          this.uploadSuccess = true;
        }, error => {
          this.SET_LOADING(false);
          alert('Error: ' + (error.response ? error.response.data : error));
        });
      }
    }

  }
</script>
