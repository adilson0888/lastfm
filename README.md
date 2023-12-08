# lastfm random artists' fetcher
Web application to fetch a random list of artists from a lastfm user library

This little web app was made to help me select a list of randam artists from my own lastfm library. It's usefull when you have a large library and can't remember all the artists you've listened to or can't decide what to listen next. 

As a Google Play Music (GPM) user and future YT Music user, there's no official APIs to automatically generate playlists. So I just made a little tweak using android automation app [Tasker](https://play.google.com/store/apps/details?id=net.dinglisch.android.taskerm) to save a few clicks and open the bands the app returned directly on GPM.

## Configuring the web app
Modify the **docker-compose.yml** file to set your LASTFM_USER and LASTFM_APIKEY env variables.

**LASTFM_USER** parameter is the user of your account or someone's else you would like to obtain informations about it's library

**LASTFM_APIKEY** parameter is the lastfm developer key to use it's APIs. It can be generated [here](https://www.last.fm/api/account/create)

## Usgin the web app
![setup](https://imgshare.io/images/2020/05/25/Capture01.png)
### 01. How many artists
Slide for amount of artists you would like to retrieve
### 02. Adventure's Tunning
Adjust the kind of artists you would like to retreive. Here's how the logic is applyed to selected ther artists from the library

Lastfm orders the artists in the library based on how many times you've listened to each artists, so the artists you hear the most will be on the top of the list.
The app groups the artists in five sections (classics, known, adventurous, wild and insanes --dont mind about the names) 

The categories correspond to a percentage portion of the library as follows:

- classics : 5%
- known : 15%
- adventurous : 25%
- wild : 25%
- insane : 30% 

In this section, each slides correponds to the amount of artists you want in your list from the respective category. For exemple, if you put 3 in classics (first vertical slide) you will get 3 randomlly picked artists of the firts 5% of the library.
The quantity of this slides sums the total selected on ther first stage.

### 03. Reviewing
Just a description of what you've configured in the previous sections

Click on Fetch button.

### 04. Results
![results](https://imgshare.io/images/2020/05/25/Capture02.png)

The list of bands requested will show with the following info:
- Artist name;
- Artist tasker url to be used as described on next section (you can change this url to directly open your favorite music streamming service);
- Tags from the music style of each artist;
- Should return an image of the correponding artist, but lastfm removed this option from its APis and now we just have a giant star;
- The number of times you have listened to that artist.

## Using Tasker Project
You will notice that all artists urls are pointing something like tasker://Iron Maiden, this is a internal url that can be intercepted by tasker and execute the script to automatically open this artist on GPM.
To use this tasker project, you just need to import the file **tasker/LastFm.prj.xml** on your Tasker app. 
To use the entire project you will need the following apps:
- [Tasker](https://play.google.com/store/apps/details?id=net.dinglisch.android.taskerm) (Required)
- [AutoApps](https://play.google.com/store/apps/details?id=com.joaomgcd.auto) (Required)
- [AutoTools](https://play.google.com/store/apps/details?id=com.joaomgcd.autotools) (Optional - you can adapt a simpler version of the project to not use this plugin)
> Don't forget to enable the option **Allow Phone Access** inside scene, otherwise the webview cannot load javascript resources and point the scene to the correct url where you are hosting the web app.

For further information about how you can use and configure Tasker and it's plugins, you can find a lot of tutorials and help in https://joaoapps.com/
