# Asterisk-AGISpeedy

This Project moved from http://agispeedy.googlecode.com

High Performance Asterisk Gateway Interface(AGI) implements, Speedup much more 300% and low processor usage times,
all was worte in php natural scripts, also safety and performance at embedded hardware enviroment like MT7620 etc.

folder structures:

etc/ config files.

bin/ agispeedy php runtime.

contrib/  centos init files(who can customize it).

agiscripts/  includes demo of agispeedy.

Usage:

php5 enviroment and linux system:

php agispeedy.php --verbose

in asterisk extensions.conf:

exten => 800,1,agi(agi://127.0.0.1/demo,playback=123)
