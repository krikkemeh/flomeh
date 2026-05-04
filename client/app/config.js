import http from 'axios';
http.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('#token').getAttribute('content');

const {env, url, uri, auth, language, posterTmdb, posterSubpageTmdb, backdropTmdb} = document.body.dataset;

const config = {
  env,
  uri,
  url,
  auth,
  language,
  poster: url + '/assets/poster',
  backdrop: url + '/assets/backdrop',
  posterSubpage: url + '/assets/poster/subpage',
  posterTMDB: posterTmdb,
  posterSubpageTMDB: posterSubpageTmdb,
  backdropTMDB: backdropTmdb,
  api: url + '/api'
};

http.interceptors.response.use(response => response, error => {
  if(error && error.response && error.response.status === 401) {
    window.location.replace(url + '/login');
  }

  return Promise.reject(error);
});

window.config = config;

export default config;
