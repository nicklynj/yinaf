


var api = function() {
  if (!(this instanceof api)) {
    var obj = new api();
    return obj.request.apply(obj, arguments);
  }
};


api.prototype.url = 'yinaf/';


api.prototype.request = function(cls, fnct, opt_args, opt_success, opt_xhr) {

  var xhr = opt_xhr || (new rocket.XMLHttpRequest());

  xhr.data = {
    'class': cls,
    'function': fnct,
    'arguments': rocket.JSON.stringify((opt_args === undefined) ? null : opt_args)
  };

  xhr.open('POST', this.url);
  
  xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

  xhr.addEventListener('success', function() {
    try {
      var response = rocket.JSON.parse(this.responseText);
      if (response.success) {
        if (opt_success) {
          opt_success(response.result);
        }
      } else {
        throw response.result;
      }
    } catch (e) {
      if (exception) {
        exception(e);
      }
    }
  });
  
  xhr.addEventListener('error', function() {
    if (exception) {
      exception({'message': 'XMLHttpRequest failure'});
    }
  });

  xhr.send();

  return xhr;
  
};
