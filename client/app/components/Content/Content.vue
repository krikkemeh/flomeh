<template>
  <main>
    <div class="content-submenu" v-if=" ! loading && items.length">
      <div class="content-tools no-select">
        <div class="sort-wrap">
          <div class="sort-direction" @click="setUserSortDirection()">
            <i v-if="userSortDirection == 'asc'">&#8593;</i>
            <i v-if="userSortDirection == 'desc'">&#8595;</i>
          </div>
          <div class="filter-wrap">
            <span class="current-filter" @click="toggleShowFilters()">{{ lang(userFilter) }} <span class="arrow-down"></span></span>
            <ul class="all-filters" :class="{active: showFilters}">
              <li v-if="filter !== userFilter" v-for="filter in filters" @click="setUserFilter(filter)">{{ lang(filter) }}</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

      <div class="wrap-content item-grid" v-if=" ! loading">
      <template v-for="entry in displayEntries">
          <div class="completed-separator"
               v-if="entry.type === 'separator'"
               :class="{clickable: entry.action}"
               :key="entry.key"
               @click="runSeparatorAction(entry)">
            <span>{{ entry.label }}</span>
          </div>

          <Item v-if="entry.type === 'item'"
                :item="entry.item"
                :key="entry.key"
                :genre="displayGenre"
                :date="displayDate"
                :ratings="displayRatings"
          ></Item>
      </template>

      <span class="nothing-found" v-if=" ! items.length">{{ emptyMessage }}</span>

      <div class="load-more-wrap">
        <span class="load-more" v-if="showHomeGroups && itemGroups.not_watching" @click="toggleNotWatching()">
          {{ showNotWatching ? 'HIDE NOT WATCHING' : 'SHOW NOT WATCHING' }}
        </span>
        <span class="load-more" v-if="showHomeGroups && itemGroups.completed" @click="toggleSeen()">
          {{ showSeen ? 'HIDE SEEN' : 'SHOW SEEN' }}
        </span>
      </div>
    </div>

    <span class="loader fullsize-loader" v-if="loading"><i></i></span>
  </main>
</template>

<script>
  import Item from './Item.vue';
  import { mapActions, mapState, mapMutations } from 'vuex'
  import MiscHelper from '../../helpers/misc';

  import http from 'axios';

  export default {
    mixins: [MiscHelper],

    created() {
      this.fetchData();
      this.fetchSettings();
    },

    data() {
      return {
        displayGenre: null,
        displayDate: null,
        displayRatings: null,
        showNotWatching: false,
        showSeen: false
      }
    },

    computed: {
      ...mapState({
        filters: state => state.filters,
        showFilters: state => state.showFilters,
        loading: state => state.loading,
        items: state => state.items,
        userFilter: state => state.userFilter,
        userSortDirection: state => state.userSortDirection,
        clickedMoreLoading: state => state.clickedMoreLoading,
        paginator: state => state.paginator,
        itemGroups: state => state.itemGroups
      }),

      watchingItems() {
        return this.items.filter(item => ! this.isCompletedItem(item) && item.watching_now);
      },

      notWatchingItems() {
        return this.items.filter(item => ! this.isCompletedItem(item) && ! item.watching_now);
      },

      completedItems() {
        return this.items.filter(item => this.isCompletedItem(item));
      },

      displayEntries() {
        if( ! this.showHomeGroups) {
          return this.items.map((item, index) => this.itemEntry(item, index));
        }

        let entries = [];

        if(this.watchingItems.length) {
          entries.push({type: 'separator', label: 'Watching now', key: 'separator-watching', action: null});
        }

        entries = entries.concat(this.watchingItems.map((item, index) => this.itemEntry(item, index)));

        if(this.notWatchingItems.length) {
          entries.push({type: 'separator', label: 'Not watching now', key: 'separator-not-watching', action: 'notWatching'});
          entries = entries.concat(this.notWatchingItems.map((item, index) => this.itemEntry(item, index)));
        }

        if(this.completedItems.length) {
          entries.push({type: 'separator', label: 'Seen or completed', key: 'separator-completed', action: 'seen'});
          entries = entries.concat(this.completedItems.map((item, index) => this.itemEntry(item, index)));
        }

        return entries;
      },

      showHomeGroups() {
        return this.$route.name === 'home';
      },

      emptyMessage() {
        if(this.$route.name === 'watchlist') {
          return 'Your watchlist is empty. Search for a movie or TV show, then use Add to Watchlist before rating it as watched.';
        }

        return this.lang('nothing found');
      }
    },

    methods: {
      ...mapActions([ 'loadItems', 'loadMoreItems', 'setSearchTitle', 'setPageTitle' ]),
      ...mapMutations([ 'SET_USER_FILTER', 'SET_SHOW_FILTERS', 'SET_USER_SORT_DIRECTION' ]),

      fetchData() {
        let name = this.$route.name;

        this.setTitle(name);
        this.loadItems({
          name,
          includeNotWatching: this.showHomeGroups && this.showNotWatching,
          includeCompleted: this.showHomeGroups && this.showSeen
        });
        this.setSearchTitle('');
      },

      setTitle(name) {
        switch(name) {
          case 'home':
            return this.setPageTitle();
          case 'tv':
          case 'movie':
          case 'watchlist':
            return this.setPageTitle(this.lang(name));
        }
      },

      fetchSettings() {
        http(`${config.api}/settings`).then(value => {
          const data = value.data;

          this.displayGenre = data.genre;
          this.displayDate = data.date;
          this.displayRatings = data.ratings;
        });
      },

      loadMore() {
        this.loadMoreItems(this.paginator);
      },

      toggleShowFilters() {
        this.SET_SHOW_FILTERS( ! this.showFilters);
      },

      toggleNotWatching() {
        this.showNotWatching = ! this.showNotWatching;
        this.fetchData();
      },

      toggleSeen() {
        this.showSeen = ! this.showSeen;
        this.fetchData();
      },

      runSeparatorAction(entry) {
        if(entry.action === 'notWatching') {
          this.toggleNotWatching();
        }

        if(entry.action === 'seen') {
          this.toggleSeen();
        }
      },

      itemEntry(item, index) {
        return {
          type: 'item',
          item,
          key: 'item-' + item.id + '-' + index
        };
      },

      isCompletedItem(item) {
        if(item.watchlist) {
          return false;
        }

        if(item.media_type === 'tv') {
          return item.rating !== null && item.tmdb_id && ! item.latest_episode;
        }

        return item.media_type === 'movie' && item.rating !== null && item.rating != 0;
      },

      setUserFilter(filter) {
        this.SET_SHOW_FILTERS(false);

        localStorage.setItem('filter', filter);
        this.SET_USER_FILTER(filter);
        this.fetchData();
      },

      setUserSortDirection() {
        let newSort = this.userSortDirection === 'asc' ? 'desc' : 'asc';

        localStorage.setItem('sort-direction', newSort);
        this.SET_USER_SORT_DIRECTION(newSort);
        this.fetchData();
      }
    },

    components: {
      Item
    },

    watch: {
      $route() {
        this.showNotWatching = false;
        this.showSeen = false;
        this.fetchData();
      }
    }
  }
</script>
