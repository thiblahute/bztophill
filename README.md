bzxmltophill
============

bzxmltophill is a tool that takes the `bugs.xml` file generated from the
bugzilla /xmlrpc.cgi interface and converts it to the JSON format used
by [Phill](https://git.collabora.com/cgit/user/em/phabricator.git/log/?h=phill)

By using bzxmltophill, it is possible to migrate projects and tasks
from a Bugzilla instance to Maniphest (if you don't look too close).

Be warned: there are plenty of rough edges, a lot of stuff cannot be migrated
and some stuff that could be migrated is ignored because we didn't care enough
to bother. But it worked for us, so YMMV.


Usage
-----

```
# Go to bugzilla web interface and generate a XML file from a list of bugs (probably all the bugs
for a product/component
# Import all the users from that XML file

  $ ./bzuserstophab bugs.xml /srv/http/phabricator/scripts/user/add_user.php phab_admin_username

# tell bzxmltophill the base URL of the Bugzilla instance, so that it can link back to the original issues.

  $ ./bzxmltophill bugs.xml --base-url https://jira.example.org/browse > tasks.json

# ta-dah! fill Maniphest with useless chatter and let's go shopping
  $ phill tasks.json -v 2


# In one line:
  $ ./bzuserstophab bugs.xml /srv/http/phabricator/scripts/user/add_user.php phab_admin_username  && \
    ./bzxmltophill bugs.xml > tasks.json && \
    /srv/http/phabricator/scripts/phill/phill.php --input tasks.json -v 2


```

Those scripts are in big part taken from [Jinson & Johill](https://github.com/em-/jinson-and-johill/)
