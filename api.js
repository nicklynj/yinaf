


var api = function(cls, fnct, opt_args, opt_success, opt_xhr) {

  var xhr = opt_xhr || (new rocket.XMLHttpRequest());

  xhr.data = {
    'class': cls,
    'function': fnct,
    'arguments': rocket.JSON.stringify((opt_args === undefined) ? null : opt_args)
  };

  xhr.open('POST', 'moxee_api');
  
  xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

  xhr.addEventListener('success', function() {
    try {
      var response = rocket.JSON.parse(this.responseText);
      if (response.success) {
        if (opt_success) {
          opt_success(response.result);
        }
      } else {
        throw response.error;
      }
    } catch (e) {
      if (error) {
        error(e);
      }
    }
  });
  
  xhr.addEventListener('error', function() {
    if (error) {
      error('XMLHttpRequest failure');
    }
  });

  xhr.send();

};
