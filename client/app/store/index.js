import Vue from 'vue';
import Vuex from 'vuex'

import * as actions from './actions';
import mutations from './mutations';

Vue.use(Vuex);

export default new Vuex.Store({
  state: {
    filters: [
      'last seen',
      'own rating',
      'title',
      'release',
      'tmdb rating',
      'imdb rating'
    ],
    showFilters: false,
    items: [],
    itemGroups: {
      watching: 0,
      not_watching: 0,
      completed: 0
    },
    searchTitle: '',
    userFilter: '',
    userSortDirection: '',
    loading: false,
    clickedMoreLoading: false,
    paginator: null,
    colorScheme: '',
    overlay: false,
    modalData: {},
    loadingModalData: true,
    seasonActiveModal: 1,
    modalType: '',
    itemLoadedSubpage: false
  },
  mutations,
  actions
});
