(function ($, Drupal) {
  "use strict";

  Drupal.behaviors.twitterMediaEntity = {

    createTweets: function (context) {
      // this === window
      $('blockquote.twitter-tweet > a', context).each(function () {
        // this === link
        var tweetID = this.href.substr(this.href.lastIndexOf('/') + 1);
        twttr.widgets.createTweet(tweetID, this.parentNode);
      });
    },

    attach: function (context) {
      if (typeof twttr === 'undefined') {
        $.getScript('//platform.twitter.com/widgets.js', this.createTweets.bind(window, context));
      }
      else {
        this.createTweets(context);
      }
    }

  };

})(jQuery, Drupal);
